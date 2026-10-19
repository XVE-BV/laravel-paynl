<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use PayNL\Sdk\Model\Pay\PayStatus;
use Throwable;

/**
 * One status model for every Pay. state code, whether it came from a status call or the exchange.
 * The raw code stays available as Payment::$statusCode for the rare cases this folds together
 * (e.g. -80 expired and -60 failure are both Cancelled, 80 partial payment is still Pending).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Verify = 'verify';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case PartiallyRefunded = 'partially_refunded';
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
            // Held by Pay.'s fraud check: tell the customer the order is received, but do not ship until approve()
            PayStatus::VERIFY => self::Verify,
            PayStatus::CANCEL, PayStatus::VOID, PayStatus::DENIED => self::Cancelled,
            PayStatus::PARTIAL_REFUND => self::PartiallyRefunded,
            PayStatus::REFUND => self::Refunded,
            PayStatus::CHARGEBACK => self::Chargeback,
            default => self::Pending,
        };
    }

    /**
     * Pay. is done with the order: a kassa can stop polling. Verify and Authorized still wait on a decision.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled, self::PartiallyRefunded, self::Refunded, self::Chargeback], true);
    }
}
