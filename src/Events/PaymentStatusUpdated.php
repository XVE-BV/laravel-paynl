<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Xve\LaravelPaynl\Support\Payment;

/**
 * Fired for every verified exchange call. Pay. can send the same call more than once and not
 * always in order, so listeners must be idempotent and never move an order back (paid stays paid
 * when a late pending arrives). Look the order up by $payment->orderId (or $payment->reference)
 * and ignore payments that are not yours: every package in the app gets every Pay. event.
 */
class PaymentStatusUpdated
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
