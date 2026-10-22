<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Listeners;

use Illuminate\Support\Facades\Auth;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Events\TerminalPaymentPaid;
use Xve\LaravelPaynl\Services\TerminalPayments;

/**
 * Turns a paid exchange call for a terminal payment that is not booked yet into TerminalPaymentPaid,
 * so the app can book it when the kassa never does.
 */
class DispatchTerminalPaymentPaid
{
    public function __construct(private readonly TerminalPayments $terminalPayments) {}

    public function handle(PaymentStatusUpdated $event): void
    {
        if (! $event->payment->isPaid()) {
            return;
        }

        // Every Pay. order of the app comes through here; only terminal payments started with TerminalPayments
        $terminalPayment = $this->terminalPayments->find($event->payment->orderId);
        if (! $terminalPayment || $this->terminalPayments->isBooked($terminalPayment->orderId)) {
            return;
        }

        // The exchange has no user: book as the cashier who started it (their period, their name in the log)
        $user = $terminalPayment->userId ? Auth::getProvider()->retrieveById($terminalPayment->userId) : null;
        if ($user) {
            Auth::setUser($user);
        }

        try {
            TerminalPaymentPaid::dispatch($terminalPayment, $event->payment);
        } finally {
            if ($user) {
                Auth::forgetUser();
            }
        }
    }
}
