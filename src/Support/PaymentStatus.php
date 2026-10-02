<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use PayNL\Sdk\Model\Pay\PayStatus;
use Throwable;

/**
 * One status model for every Pay. state code, whether it came from a status call or the exchange.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Chargeback = 'chargeback';

    public static function fromPayCode(int $code): self
    {
        try {
            $state = (new PayStatus)->get($code);
        } catch (Throwable) {
            return self::Pending;
        }

        return match ($state) {
            PayStatus::PAID => self::Paid,
            PayStatus::AUTHORIZE, PayStatus::PARTLY_CAPTURED => self::Authorized,
            PayStatus::CANCEL, PayStatus::VOID, PayStatus::DENIED => self::Cancelled,
            PayStatus::REFUND, PayStatus::PARTIAL_REFUND => self::Refunded,
            PayStatus::CHARGEBACK => self::Chargeback,
            default => self::Pending,
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
