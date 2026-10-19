<?php

use PayNL\Sdk\Model\Request\OrderCreateRequest;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Exceptions\PaymentNotVerifiedException;
use Xve\LaravelPaynl\Facades\Paynl;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Testing\FakePaynl;

it('swaps the gateway for the fake, but keeps the exchange real', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(PaynlService::class);

    Paynl::fake();

    expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaynl::class)
        ->and(app(PaynlService::class))->toBeInstanceOf(PaynlService::class);
});

it('records started payments and lets a test move them along', function () {
    $paynl = Paynl::fake();

    $payment = Paynl::startOnlinePayment(2500, 'Webshop', 'https://shop.test/done', 'ORD-1');
    Paynl::startTerminalPayment(1210, 'TH-1111-2222', 'Kassa');

    $paynl->assertPaymentStarted(fn (array $started) => $started['type'] === 'online' && $started['amount'] === 2500);
    $paynl->assertPaymentStarted(fn (array $started) => $started['type'] === 'terminal' && $started['terminalCode'] === 'TH-1111-2222');
    expect($payment->paymentUrl)->toStartWith('https://pay.test/')
        ->and(Paynl::getStatus($payment->orderId)->status)->toBe(PaymentStatus::Pending);

    $paynl->markAs($payment->orderId, PaymentStatus::Paid);

    expect(Paynl::verifyPaid($payment->orderId, 2500)->isPaid())->toBeTrue()
        ->and(fn () => Paynl::verifyPaid($payment->orderId, 2400))->toThrow(PaymentNotVerifiedException::class);
});

it('runs the configure callback so a test can check the order data', function () {
    $paynl = Paynl::fake();

    Paynl::startOnlinePayment(2500, 'Webshop', 'https://shop.test/done', configure: fn (OrderCreateRequest $request) => $request->setDescription('With customer'));

    $paynl->assertPaymentStarted(fn (array $started) => $started['request']->getBodyParameters()['description'] === 'With customer');
});

it('tracks refunds', function () {
    $paynl = Paynl::fake();
    $payment = Paynl::startOnlinePayment(2500, 'Webshop', 'https://shop.test/done');
    $paynl->markAs($payment->orderId, PaymentStatus::Paid);

    Paynl::refund($payment->orderId, 500);

    $paynl->assertRefunded(fn (array $refund) => $refund['amount'] === 500);
    expect(Paynl::getStatus($payment->orderId)->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('asserts that nothing was started', function () {
    Paynl::fake()->assertNothingStarted();
});
