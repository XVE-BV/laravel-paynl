<?php

declare(strict_types=1);

namespace Xve\LaravelPaynl\Http\Client;

use GuzzleHttp\Client;
use PayNL\Sdk\Http\GuzzleClientFactoryInterface;

/**
 * The SDK's own client has no timeout, so one slow Pay. call would hang the kassa or the worker.
 */
final class TimeoutGuzzleClientFactory implements GuzzleClientFactoryInterface
{
    public function __construct(
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {}

    public function create(array $config): Client
    {
        return new Client(array_merge($config, [
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ]));
    }
}
