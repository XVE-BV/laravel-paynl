<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Xve\LaravelPaynl\Support\Payment;

/**
 * Fired for every verified exchange call. Pay. can send the same call more than
 * once, so listeners must be idempotent (look up by $payment->orderId).
 */
class PaymentStatusUpdated
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
