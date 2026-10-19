<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Concerns;

use Xve\LaravelPaynl\Exceptions\PaymentNotVerifiedException;
use Xve\LaravelPaynl\Support\Payment;

/**
 * verifyPaid() on top of getStatus(), shared by the real gateway and the fake.
 */
trait VerifiesPayments
{
    abstract public function getStatus(string $orderId): Payment;

    public function verifyPaid(string $orderId, int $amount): Payment
    {
        $payment = $this->getStatus($orderId);

        if (! $payment->isPaid()) {
            throw PaymentNotVerifiedException::notPaid($payment);
        }

        if ($payment->amount !== $amount) {
            throw PaymentNotVerifiedException::amountMismatch($payment, $amount);
        }

        return $payment;
    }
}
