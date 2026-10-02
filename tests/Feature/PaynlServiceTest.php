<?php

use PayNL\Sdk\Model\Amount;
use PayNL\Sdk\Model\Pay\PayOrder;
use PayNL\Sdk\Model\Request\OrderCreateRequest;
use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Model\Request\TerminalsBrowseRequest;
use PayNL\Sdk\Model\Terminal as PayTerminal;
use PayNL\Sdk\Model\Terminals;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\PaymentStatus;

function payOrder(int $statusCode, float $amount = 12.10): PayOrder
{
    return (new PayOrder)
        ->setOrderId('EX-1234-5678-9012')
        ->setStatusCodeName($statusCode, 'STATE')
        ->setAmount(new Amount((int) round($amount * 100), 'EUR'));
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

it('sends a terminal payment to the chosen terminal with the stats fields', function () {
    config(['paynl.stats.object' => 'TEST_PARTNER_VALUE', 'paynl.stats.tool' => 'Nassau']);
    $sent = null;

    $payment = paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return payOrder(20);
    })->startTerminalPayment(12.1, 'TH-1111-2222', 'Kassa');

    expect($sent)->toBeInstanceOf(OrderCreateRequest::class);
    $body = $sent->getBodyParameters();
    expect($body['paymentMethod']['id'])->toBe(PaynlService::TERMINAL_PAYMENT_METHOD)
        ->and($body['paymentMethod']['input']['terminalCode'])->toBe('TH-1111-2222')
        ->and($body['serviceId'])->toBe('SL-0000-0000')
        ->and($body['amount']['value'])->toBe(1210)
        ->and($body['stats']['object'])->toBe('TEST_PARTNER_VALUE')
        ->and($body['stats']['tool'])->toBe('Nassau')
        ->and($body['exchangeUrl'])->toBe(route('paynl.exchange'))
        ->and($payment->orderId)->toBe('EX-1234-5678-9012')
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('starts an online payment and hands back the payment url', function () {
    $order = payOrder(20)->setLinks(['redirect' => 'https://pay.nl/checkout/abc']);

    $payment = paynlWith(fn () => $order)->startOnlinePayment(25, 'Webshop', 'https://shop.test/done', 'ORD-1');

    expect($payment->paymentUrl)->toBe('https://pay.nl/checkout/abc');
});

it('normalises the Pay. status codes', function (int $code, PaymentStatus $status) {
    $payment = paynlWith(fn ($request) => $request instanceof OrderStatusRequest ? payOrder($code) : null)->getStatus('EX-1234-5678-9012');

    expect($payment->status)->toBe($status);
})->with([
    'paid' => [100, PaymentStatus::Paid],
    'pending' => [20, PaymentStatus::Pending],
    'verify' => [85, PaymentStatus::Pending],
    'authorized' => [95, PaymentStatus::Authorized],
    'cancelled' => [-90, PaymentStatus::Cancelled],
    'denied' => [-63, PaymentStatus::Cancelled],
    'refunded' => [-81, PaymentStatus::Refunded],
    'chargeback' => [-71, PaymentStatus::Chargeback],
    'unknown' => [12345, PaymentStatus::Pending],
]);

it('carries the reason the terminal stopped the payment', function () {
    $order = payOrder(-90)->setPayments([
        ['id' => 'PA-1', 'supplierData' => ['incident_code' => '2620', 'incident_text' => 'Cancelled by user']],
    ]);

    $payment = paynlWith(fn () => $order)->getStatus('EX-1234-5678-9012');

    expect($payment->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->failureReason)->toBe('Cancelled by user')
        ->and(paynlWith(fn () => payOrder(100))->getStatus('EX-1234-5678-9012')->failureReason)->toBeNull();
});

it('lists the terminals with a usable flag', function () {
    $terminals = (new Terminals)
        ->addTerminal((new PayTerminal)->setCode('TH-1')->setName('Kassa 1')->setStatus('ACTIVE')->setConnectionStatus('ONLINE'))
        ->addTerminal((new PayTerminal)->setCode('TH-2')->setName('Kassa 2')->setStatus('ACTIVE')->setConnectionStatus('OFFLINE'))
        ->addTerminal((new PayTerminal)->setCode('TH-3')->setName('Kassa 3')->setStatus('BLOCKED')->setConnectionStatus('ONLINE'));

    $list = paynlWith(fn ($request) => $request instanceof TerminalsBrowseRequest ? $terminals : null)->listTerminals();

    expect(array_map(fn ($terminal) => [$terminal->code, $terminal->isUsable()], $list))
        ->toBe([['TH-1', true], ['TH-2', false], ['TH-3', false]]);
});

it('refuses to call Pay. without credentials', function () {
    config(['paynl.api_token' => null]);

    app(PaynlService::class)->getStatus('EX-1234-5678-9012');
})->throws(PaynlException::class);
