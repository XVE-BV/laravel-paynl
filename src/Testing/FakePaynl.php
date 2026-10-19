<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Testing;

use PayNL\Sdk\Model\Amount;
use PHPUnit\Framework\Assert as PHPUnit;
use stdClass;
use Xve\LaravelPaynl\Concerns\VerifiesPayments;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Support\OrderRequest;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Support\Receipt;
use Xve\LaravelPaynl\Support\Terminal;

/**
 * In-memory stand-in for Pay., for the tests of an app or package that takes payments:
 *
 *     $paynl = Paynl::fake();
 *     // ... code that calls Paynl::startOnlinePayment(2500, ...)
 *     $paynl->assertPaymentStarted(fn (array $started) => $started['amount'] === 2500);
 *     $paynl->markAs($paynl->lastPayment()->orderId, PaymentStatus::Paid);
 *
 * Each started payment is recorded as an array with: type (terminal, terminal_refund,
 * online), amount, description, reference, terminalCode, returnUrl, paymentMethodId, request
 * (the OrderRequest any $configure callback filled) and payment.
 */
final class FakePaynl implements PaymentGateway
{
    use VerifiesPayments;

    private stdClass $state;

    public function __construct()
    {
        $this->state = (object) ['payments' => [], 'started' => [], 'refunds' => [], 'terminals' => [], 'sequence' => 0];
    }

    /**
     * The terminals listTerminals() returns.
     */
    public function withTerminals(Terminal ...$terminals): self
    {
        $this->state->terminals = $terminals;

        return $this;
    }

    /**
     * Moves an order to a status, as the exchange would. An order the fake does not know yet is created.
     */
    public function markAs(string $orderId, PaymentStatus $status, ?int $amount = null, ?string $failureReason = null, ?Receipt $receipt = null): Payment
    {
        $current = $this->state->payments[$orderId] ?? null;

        return $this->state->payments[$orderId] = new Payment(
            orderId: $orderId,
            status: $status,
            amount: $amount ?? $current?->amount ?? 0,
            currency: $current?->currency ?? 'EUR',
            reference: $current?->reference ?? '',
            paymentUrl: $current?->paymentUrl,
            statusUrl: $current?->statusUrl,
            failureReason: $failureReason,
            receipt: $receipt,
        );
    }

    public function listTerminals(): array
    {
        return $this->state->terminals;
    }

    public function startTerminalPayment(int $amount, string $terminalCode, string $description, string $reference = '', ?callable $configure = null): Payment
    {
        return $this->start('terminal', $amount, $description, $reference, terminalCode: $terminalCode, configure: $configure);
    }

    public function startTerminalRefund(int $amount, string $terminalCode, string $description, string $reference = ''): Payment
    {
        return $this->start('terminal_refund', $amount, $description, $reference, terminalCode: $terminalCode);
    }

    public function startOnlinePayment(int $amount, string $description, string $returnUrl, string $reference = '', ?int $paymentMethodId = null, ?callable $configure = null): Payment
    {
        return $this->start('online', $amount, $description, $reference, returnUrl: $returnUrl, paymentMethodId: $paymentMethodId, configure: $configure);
    }

    public function getStatus(string $orderId): Payment
    {
        return $this->state->payments[$orderId] ?? throw new PaynlException("Pay. order {$orderId} does not exist (fake).");
    }

    public function cancel(string $orderId): Payment
    {
        return $this->markAs($orderId, PaymentStatus::Cancelled, $this->getStatus($orderId)->amount);
    }

    public function refund(string $orderId, ?int $amount = null, string $description = ''): void
    {
        $payment = $this->getStatus($orderId);
        $this->state->refunds[] = ['orderId' => $orderId, 'amount' => $amount, 'description' => $description];

        $full = $amount === null || $amount >= $payment->amount;
        $this->markAs($orderId, $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded);
    }

    public function approve(string $orderId): Payment
    {
        return $this->markAs($orderId, PaymentStatus::Paid, $this->getStatus($orderId)->amount);
    }

    public function decline(string $orderId): Payment
    {
        return $this->markAs($orderId, PaymentStatus::Cancelled, $this->getStatus($orderId)->amount);
    }

    public function capture(string $orderId, ?int $amount = null): Payment
    {
        return $this->markAs($orderId, PaymentStatus::Paid, $amount ?? $this->getStatus($orderId)->amount);
    }

    public function void(string $orderId): Payment
    {
        return $this->markAs($orderId, PaymentStatus::Cancelled, $this->getStatus($orderId)->amount);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function started(): array
    {
        return $this->state->started;
    }

    public function lastPayment(): ?Payment
    {
        $last = end($this->state->started);

        return $last ? $this->state->payments[$last['payment']->orderId] : null;
    }

    /**
     * @param  (callable(array): bool)|null  $callback
     */
    public function assertPaymentStarted(?callable $callback = null): void
    {
        $matches = array_filter($this->state->started, $callback ?? fn () => true);

        PHPUnit::assertNotEmpty($matches, 'No matching Pay. payment was started.');
    }

    public function assertNothingStarted(): void
    {
        PHPUnit::assertEmpty($this->state->started, count($this->state->started).' Pay. payment(s) were started unexpectedly.');
    }

    /**
     * @param  (callable(array): bool)|null  $callback  gets orderId, amount (null = full) and description
     */
    public function assertRefunded(?callable $callback = null): void
    {
        $matches = array_filter($this->state->refunds, $callback ?? fn () => true);

        PHPUnit::assertNotEmpty($matches, 'No matching Pay. refund was made.');
    }

    private function start(string $type, int $amount, string $description, string $reference, ?string $terminalCode = null, ?string $returnUrl = null, ?int $paymentMethodId = null, ?callable $configure = null): Payment
    {
        // Filled like the real gateway does, so the callback and the assertions see a complete order
        $request = (new OrderRequest)
            ->setServiceId('SL-FAKE-0000')
            ->setAmount(new Amount($amount, 'EUR'))
            ->setDescription($description)
            ->setReturnurl($returnUrl ?? 'https://pay.test/return');
        if ($reference !== '') {
            $request->setReference($reference);
        }
        if ($paymentMethodId !== null) {
            $request->setPaymentMethodId($paymentMethodId);
        }
        if ($configure !== null) {
            $configure($request);
        }

        $orderId = sprintf('FAKE-%04d', ++$this->state->sequence);
        $payment = $this->state->payments[$orderId] = new Payment(
            orderId: $orderId,
            status: PaymentStatus::Pending,
            amount: $amount,
            reference: $reference,
            paymentUrl: $type === 'online' ? "https://pay.test/checkout/{$orderId}" : null,
        );

        $this->state->started[] = [
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'reference' => $reference,
            'terminalCode' => $terminalCode,
            'returnUrl' => $returnUrl,
            'paymentMethodId' => $paymentMethodId,
            'request' => $request,
            'payment' => $payment,
        ];

        return $payment;
    }
}
