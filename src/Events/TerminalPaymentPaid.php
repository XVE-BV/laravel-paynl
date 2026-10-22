<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\TerminalPayment;

/**
 * The exchange reports a terminal payment as paid that is not booked yet (the kassa closed, lost its
 * connection, ...). Fired as the user who started it. The app books it from $terminalPayment->context
 * through TerminalPayments::book(), which leaves it alone when the kassa books it first. A listener
 * that throws makes Pay. call the exchange again later.
 */
class TerminalPaymentPaid
{
    use Dispatchable;

    public function __construct(
        public readonly TerminalPayment $terminalPayment,
        public readonly Payment $payment,
    ) {}
}
