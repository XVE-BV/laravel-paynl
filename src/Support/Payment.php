<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use PayNL\Sdk\Model\Pay\PayOrder;
use Throwable;

final class Payment implements Arrayable, JsonSerializable
{
    private const APPROVED_INCIDENT = '0000';

    /**
     * @param  int  $amount  in cents
     * @param  ?string  $statusUrl  Pay.'s public status link (no credentials needed), for a kassa that polls itself
     * @param  int  $statusCode  the raw Pay. state code behind $status
     * @param  ?Receipt  $receipt  the card receipt, once the terminal finished a payment or refund
     */
    public function __construct(
        public readonly string $orderId,
        public readonly PaymentStatus $status,
        public readonly int $amount,
        public readonly string $currency = 'EUR',
        public readonly string $reference = '',
        public readonly ?string $paymentUrl = null,
        public readonly ?string $statusUrl = null,
        public readonly ?string $failureReason = null,
        public readonly int $statusCode = 0,
        public readonly ?Receipt $receipt = null,
    ) {}

    public static function fromPayOrder(PayOrder $order): self
    {
        // The SDK leaves typed properties uninitialised when Pay. omits them, so every getter may throw
        $read = static function (callable $getter, mixed $default = null): mixed {
            try {
                return $getter();
            } catch (Throwable) {
                return $default;
            }
        };

        $statusCode = $order->getStatusCode();
        $payments = (array) $read(fn () => $order->getPayments(), []);

        return new self(
            orderId: (string) ($read(fn () => $order->getOrderId()) ?: $read(fn () => $order->getId(), '')),
            status: PaymentStatus::fromPayCode($statusCode),
            amount: (int) round((float) $read(fn () => $order->getAmount(), 0) * 100),
            currency: (string) ($read(fn () => $order->getCurrency()) ?: 'EUR'),
            reference: (string) $read(fn () => $order->getReference(), ''),
            paymentUrl: $read(fn () => $order->getPaymentUrl()) ?: null,
            statusUrl: $read(fn () => $order->getStatusUrl()) ?: null,
            failureReason: self::failureReason($payments),
            statusCode: $statusCode,
            receipt: Receipt::fromPay($read(fn () => $order->getReceipt()), $payments),
        );
    }

    /**
     * The `object` of an exchange call whose signature checked out.
     */
    public static function fromExchangeObject(array $object): self
    {
        $statusCode = (int) data_get($object, 'status.code', 0);

        return new self(
            orderId: (string) (($object['orderId'] ?? null) ?: ($object['id'] ?? '')),
            status: PaymentStatus::fromPayCode($statusCode),
            amount: (int) data_get($object, 'amount.value', 0),
            currency: (string) (data_get($object, 'amount.currency') ?: 'EUR'),
            reference: (string) ($object['reference'] ?? ''),
            paymentUrl: data_get($object, 'links.redirect') ?: null,
            statusUrl: data_get($object, 'links.status') ?: null,
            failureReason: self::failureReason((array) ($object['payments'] ?? [])),
            statusCode: $statusCode,
            receipt: Receipt::fromPay(is_string($object['receipt'] ?? null) ? $object['receipt'] : null, (array) ($object['payments'] ?? [])),
        );
    }

    /**
     * Why the terminal refused or stopped the payment (cancelled, timeout, busy, ...), as Pay. reports it.
     * Incident 0000 ("AKKOORD") is the terminal approving the payment, not a failure.
     */
    private static function failureReason(array $payments): ?string
    {
        foreach (array_reverse($payments) as $payment) {
            if ((string) data_get($payment, 'supplierData.incident_code') === self::APPROVED_INCIDENT) {
                return null;
            }

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
            'statusCode' => $this->statusCode,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'paymentUrl' => $this->paymentUrl,
            'statusUrl' => $this->statusUrl,
            'failureReason' => $this->failureReason,
            'receipt' => $this->receipt?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
