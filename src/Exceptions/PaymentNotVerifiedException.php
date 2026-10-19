<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Exceptions;

use RuntimeException;
use Xve\LaravelPaynl\Support\Payment;

/**
 * Pay. answered, but the order is not paid or not for the expected amount, so it must not be booked.
 * Not a PaynlException: that one means Pay. could not be reached or refused the call.
 */
class PaymentNotVerifiedException extends RuntimeException
{
    public const NOT_PAID = 'not_paid';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public function __construct(
        public readonly string $reason,
        public readonly Payment $payment,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notPaid(Payment $payment): self
    {
        return new self(self::NOT_PAID, $payment, "Pay. order {$payment->orderId} is {$payment->status->value}, not paid.");
    }

    public static function amountMismatch(Payment $payment, int $expected): self
    {
        return new self(self::AMOUNT_MISMATCH, $payment, sprintf('Pay. order %s is paid for %.2f, expected %.2f.', $payment->orderId, $payment->amount / 100, $expected / 100));
    }
}
