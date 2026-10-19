<?php

use PayNL\Sdk\Model\Amount;
use PayNL\Sdk\Model\Pay\PayOrder;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function payOrder(int $statusCode, int $amount = 1210, string $orderId = 'EX-1234-5678-9012'): PayOrder
{
    return (new PayOrder)
        ->setOrderId($orderId)
        ->setStatusCodeName($statusCode, 'STATE')
        ->setAmount(new Amount($amount, 'EUR'));
}

/**
 * The service with the network call swapped for $respond, which gets the SDK request that would be sent.
 */
function paynlWith(callable $respond): PaynlService
{
    $service = Mockery::mock(PaynlService::class, [config('paynl')])->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('send')->andReturnUsing($respond);

    return $service;
}

/**
 * Lets the exchange controller use $service.
 */
function exchangeUses(PaynlService $service): void
{
    app()->instance(PaynlService::class, $service);
}
