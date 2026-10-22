<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Exceptions\PaymentNotVerifiedException;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Exceptions\TerminalPaymentException;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\TerminalPayment;
use Xve\LaravelPaynl\Support\TerminalPaymentStatus;

/**
 * Everything a kassa does with a terminal payment, as Pay.'s POS certification wants it: start it once,
 * follow it with a message per outcome, stop it after a timeout, and book it exactly once, also when
 * only the exchange learns it was paid (TerminalPaymentPaid). The app keeps its screens and its booking.
 *
 * A running payment is only kept in the cache (paynl.terminal.remember seconds); the app's own
 * records stay the truth about what is booked. Amounts are integer cents.
 */
class TerminalPayments
{
    public function __construct(private readonly PaymentGateway $paynl) {}

    /**
     * Sends $amount to the terminal, once per $reference: sending the same reference again (a double
     * click, a retry after a lost answer) follows the order it started instead of charging again.
     * $context is what the app needs to book the payment; it comes back with TerminalPaymentPaid.
     *
     * @throws TerminalPaymentException when the terminal cannot take the payment
     * @throws PaynlException when Pay. cannot be reached
     */
    public function start(int $amount, string $terminalCode, string $description, string $reference, array $context = []): TerminalPaymentStatus
    {
        $lock = $this->cache()->lock('paynl:terminal:start:'.$reference, 30);
        if (! $lock->get()) {
            throw TerminalPaymentException::starting();
        }

        try {
            if ($orderId = $this->cache()->get('paynl:terminal:reference:'.$reference)) {
                return $this->status($orderId);
            }

            $this->ensureUsable($terminalCode);

            try {
                $payment = $this->paynl->startTerminalPayment($amount, $terminalCode, $description, $reference);
            } catch (PaynlException $e) {
                // Pay. refuses a terminal that lost its connection or is busy with another payment: say which
                $this->ensureUsable($terminalCode);

                throw TerminalPaymentException::terminalBusy($e);
            }

            $terminalPayment = new TerminalPayment(
                orderId: $payment->orderId,
                reference: $reference,
                terminal: $terminalCode,
                amount: $amount,
                // The exchange books it as the cashier who started it
                userId: Auth::id(),
                context: $context,
                startedAt: now()->getTimestamp(),
            );
            $this->remember($terminalPayment);
            $this->cache()->put('paynl:terminal:reference:'.$reference, $payment->orderId, $this->ttl());

            return TerminalPaymentStatus::for($payment, $terminalPayment, (int) $this->config('first_poll_ms', 3000));
        } finally {
            $lock->release();
        }
    }

    /**
     * The payment and what the kassa shows for it. A payment nobody paid within the timeout is stopped
     * here, so the terminal is free again; when Pay. still has no final answer after the grace period
     * the kassa stops asking.
     *
     * @throws PaynlException when Pay. cannot be reached
     */
    public function status(string $orderId): TerminalPaymentStatus
    {
        $payment = $this->paynl->getStatus($orderId);
        $terminalPayment = $this->find($orderId);
        $waited = $terminalPayment ? now()->getTimestamp() - $terminalPayment->startedAt : 0;
        $timeout = (int) $this->config('timeout', 180);

        if ($terminalPayment && $terminalPayment->cancelledBy === null && ! $payment->status->isFinal() && $waited > $timeout) {
            try {
                $payment = $this->paynl->cancel($orderId);
                $this->remember($terminalPayment = $terminalPayment->cancelledBy(TerminalPayment::CANCELLED_BY_TIMEOUT));
            } catch (PaynlException $e) {
                // Tried again on the next status call
                Log::warning('Pay.nl terminal payment could not be stopped after the timeout', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            }
        }

        return TerminalPaymentStatus::for(
            $payment,
            $terminalPayment,
            (int) $this->config('poll_ms', 1000),
            gaveUp: $waited > $timeout + (int) $this->config('cancel_grace', 60),
        );
    }

    /**
     * Stops a payment from the kassa. When the terminal already finished it, the status says so.
     *
     * @throws PaynlException when Pay. cannot be reached for the status
     */
    public function cancel(string $orderId): TerminalPaymentStatus
    {
        try {
            $this->paynl->cancel($orderId);

            $terminalPayment = $this->find($orderId);
            if ($terminalPayment && $terminalPayment->cancelledBy === null) {
                $this->remember($terminalPayment->cancelledBy(TerminalPayment::CANCELLED_BY_POS));
            }
        } catch (PaynlException $e) {
            Log::warning('Pay.nl terminal cancel failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        return $this->status($orderId);
    }

    /**
     * Runs $book once Pay. confirms the order is paid for $amount, inside one transaction and once per
     * order: a second call (double click, the kassa and the exchange at the same time) waits for the
     * first and gets what $book returned then. $booked tells from the app's own records that the order
     * is booked already: it returns the answer to give then, or null when it is not. A Response of 400
     * or more from $book counts as refused.
     *
     * @param  callable(Payment, TerminalPayment): mixed  $book
     * @param  (callable(): mixed)|null  $booked
     *
     * @throws TerminalPaymentException with the message for the cashier when it must not be booked
     */
    public function book(?string $orderId, int $amount, callable $book, ?callable $booked = null): mixed
    {
        // Never book a terminal payment without asking Pay.: missing credentials would make every one paid
        if ($this->paynl instanceof PaynlService && ! $this->paynl->isConfigured()) {
            throw TerminalPaymentException::notConfigured();
        }

        if (! $orderId) {
            throw TerminalPaymentException::notStarted();
        }

        $lock = $this->cache()->lock('paynl:terminal:book:'.$orderId, 60);
        try {
            $lock->block((int) $this->config('book_wait', 10));
        } catch (LockTimeoutException) {
            throw TerminalPaymentException::booking();
        }

        try {
            if ($this->cache()->has('paynl:terminal:booked:'.$orderId)) {
                return $this->cache()->get('paynl:terminal:booked:'.$orderId);
            }

            if ($booked !== null && ($answer = $booked()) !== null) {
                return $answer;
            }

            // Only payments started here, so a made-up or foreign order id books nothing
            $terminalPayment = $this->find($orderId) ?? throw TerminalPaymentException::notStarted();

            try {
                $payment = $this->paynl->verifyPaid($orderId, $amount);
            } catch (PaymentNotVerifiedException $e) {
                throw TerminalPaymentException::notVerified($e);
            } catch (PaynlException $e) {
                throw TerminalPaymentException::unverifiable($e);
            }

            $result = DB::transaction(fn () => $book($payment, $terminalPayment));

            if (! $result instanceof Response || $result->getStatusCode() < 400) {
                $this->cache()->put('paynl:terminal:booked:'.$orderId, $result instanceof Response ? null : $result, $this->ttl());
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * The running terminal payment, while the cache still knows it.
     */
    public function find(string $orderId): ?TerminalPayment
    {
        $data = $this->cache()->get('paynl:terminal:order:'.$orderId);

        return is_array($data) ? TerminalPayment::fromArray($data) : null;
    }

    public function isBooked(string $orderId): bool
    {
        return $this->cache()->has('paynl:terminal:booked:'.$orderId);
    }

    /**
     * @throws TerminalPaymentException when the terminal cannot take a payment now
     */
    private function ensureUsable(string $terminalCode): void
    {
        $terminal = collect($this->paynl->listTerminals())->first(fn ($terminal) => $terminal->code === $terminalCode);

        match (true) {
            $terminal === null => throw TerminalPaymentException::terminalUnknown(),
            $terminal->connectionStatus === 'OFFLINE' => throw TerminalPaymentException::terminalOffline(),
            ! $terminal->isUsable() => throw TerminalPaymentException::terminalInactive($terminal->status),
            default => null,
        };
    }

    private function remember(TerminalPayment $terminalPayment): void
    {
        $this->cache()->put('paynl:terminal:order:'.$terminalPayment->orderId, $terminalPayment->toArray(), $this->ttl());
    }

    private function cache(): Repository
    {
        return Cache::store($this->config('cache_store', null));
    }

    private function ttl(): int
    {
        return (int) $this->config('remember', 86400);
    }

    private function config(string $key, mixed $default): mixed
    {
        return config('paynl.terminal.'.$key, $default);
    }
}
