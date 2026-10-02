<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use PayNL\Sdk\Model\Pay\PayOrder;
use Throwable;

final class Payment implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $orderId,
        public readonly PaymentStatus $status,
        public readonly float $amount,
        public readonly string $reference = '',
        public readonly ?string $paymentUrl = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function fromPayOrder(PayOrder $order, ?PaymentStatus $status = null): self
    {
        // The SDK leaves typed properties uninitialised when Pay. omits them, so every getter may throw
        $read = static function (callable $getter, mixed $default = null): mixed {
            try {
                return $getter();
            } catch (Throwable) {
                return $default;
            }
        };

        return new self(
            orderId: (string) ($read(fn () => $order->getOrderId()) ?: $read(fn () => $order->getId(), '')),
            status: $status ?? PaymentStatus::fromPayCode($order->getStatusCode()),
            amount: (float) $read(fn () => $order->getAmount(), 0),
            reference: (string) $read(fn () => $order->getReference(), ''),
            paymentUrl: $read(fn () => $order->getPaymentUrl()) ?: null,
            failureReason: self::failureReason((array) $read(fn () => $order->getPayments(), [])),
        );
    }

    /**
     * Why the terminal refused or stopped the payment (cancelled, timeout, busy, ...), as Pay. reports it.
     */
    private static function failureReason(array $payments): ?string
    {
        foreach (array_reverse($payments) as $payment) {
            $reason = data_get($payment, 'supplierData.incident_text') ?: data_get($payment, 'supplierData.incident_code');
            if (filled($reason)) {
                return (string) $reason;
            }
        }

        return null;
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'paymentUrl' => $this->paymentUrl,
            'failureReason' => $this->failureReason,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
