<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

/**
 * A payment started on a terminal through TerminalPayments, as it is kept in the cache while it
 * runs: what was charged, by whom, what the app books for it ($context) and who stopped it.
 * $amount is in cents, $startedAt a unix timestamp.
 */
final class TerminalPayment
{
    public const CANCELLED_BY_POS = 'pos';

    public const CANCELLED_BY_TIMEOUT = 'timeout';

    public function __construct(
        public readonly string $orderId,
        public readonly string $reference,
        public readonly string $terminal,
        public readonly int $amount,
        public readonly ?int $userId = null,
        public readonly array $context = [],
        public readonly int $startedAt = 0,
        public readonly ?string $cancelledBy = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    public function cancelledBy(string $by): self
    {
        return new self(...[...$this->toArray(), 'cancelledBy' => $by]);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
