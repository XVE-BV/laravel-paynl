<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A terminal payment as the kassa screen needs it: the payment, the message to show the cashier
 * (Pay.'s certification wants one per way a terminal payment ends) and when to ask again.
 * The screen only shows `message` and polls again after `pollAfterMs`; null means stop.
 */
final class TerminalPaymentStatus implements Arrayable, JsonSerializable
{
    // Raw Pay. state codes the status model folds into Cancelled
    private const EXPIRED = -80;

    private const DENIED = [-63, -64];

    public function __construct(
        public readonly Payment $payment,
        public readonly ?string $cancelledBy = null,
        public readonly ?int $pollAfterMs = null,
        public readonly bool $gaveUp = false,
    ) {}

    public static function for(Payment $payment, ?TerminalPayment $terminalPayment, int $pollAfterMs, bool $gaveUp = false): self
    {
        $final = $payment->status->isFinal();

        return new self(
            payment: $payment,
            cancelledBy: $terminalPayment?->cancelledBy,
            pollAfterMs: $final || $gaveUp ? null : $pollAfterMs,
            gaveUp: ! $final && $gaveUp,
        );
    }

    public function isFinal(): bool
    {
        return $this->payment->status->isFinal();
    }

    public function message(): string
    {
        $payment = $this->payment;
        $reason = $payment->failureReason;

        return match (true) {
            $payment->isPaid() => __($payment->receipt?->signatureRequired ? 'paynl::terminal.paid_signature' : 'paynl::terminal.paid'),
            $this->gaveUp => __('paynl::terminal.no_answer'),
            ! $this->isFinal() => __(in_array($payment->status, [PaymentStatus::Verify, PaymentStatus::Authorized], true) ? 'paynl::terminal.verifying' : 'paynl::terminal.pending'),
            in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded, PaymentStatus::Chargeback], true) => __('paynl::terminal.refunded'),
            $this->cancelledBy === TerminalPayment::CANCELLED_BY_POS => __('paynl::terminal.cancelled_by_pos'),
            $this->cancelledBy === TerminalPayment::CANCELLED_BY_TIMEOUT => __('paynl::terminal.timeout'),
            $payment->statusCode === self::EXPIRED => __('paynl::terminal.expired'),
            in_array($payment->statusCode, self::DENIED, true) => $reason ? __('paynl::terminal.denied_reason', ['reason' => $reason]) : __('paynl::terminal.denied'),
            default => $reason ? __('paynl::terminal.stopped_reason', ['reason' => $reason]) : __('paynl::terminal.stopped'),
        };
    }

    public function toArray(): array
    {
        return $this->payment->toArray() + [
            'final' => $this->isFinal(),
            'message' => $this->message(),
            'pollAfterMs' => $this->pollAfterMs,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
