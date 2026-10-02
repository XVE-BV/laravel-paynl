<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use PayNL\Sdk\Model\Terminal as PayTerminal;
use Throwable;

final class Terminal implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $status,
        public readonly string $connectionStatus,
        public readonly array $location = [],
    ) {}

    public static function fromPayTerminal(PayTerminal $terminal): self
    {
        // Pay. returns null for unknown fields while the SDK getters are typed, so read them defensively
        $read = static function (callable $getter, mixed $default): mixed {
            try {
                return $getter() ?? $default;
            } catch (Throwable) {
                return $default;
            }
        };

        return new self(
            code: (string) $read(fn () => $terminal->getCode(), ''),
            name: (string) $read(fn () => $terminal->getName(), ''),
            status: strtoupper((string) $read(fn () => $terminal->getStatus(), '')),
            connectionStatus: strtoupper((string) $read(fn () => $terminal->getConnectionStatus(), '')),
            location: (array) $read(fn () => $terminal->getLocation(), []),
        );
    }

    /**
     * Pay. only reports OFFLINE when it knows; an unknown connection state is still worth a try.
     */
    public function isUsable(): bool
    {
        return $this->status === 'ACTIVE' && $this->connectionStatus !== 'OFFLINE';
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'connectionStatus' => $this->connectionStatus,
            'location' => $this->location,
            'usable' => $this->isUsable(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
