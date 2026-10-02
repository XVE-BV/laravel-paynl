<?php

use Illuminate\Support\Facades\Event;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Support\Terminal;
use Xve\LaravelPaynl\Tests\Sandbox\Sandbox;

/*
 * Runs against the real Pay. API with test credentials, without anyone at a terminal.
 * Every order is a sandbox order (payment method 613 and/or integration.test), so no money moves.
 * The amounts are the ones Pay.'s certification test cases look for; open the printed
 * payment url to see Pay.'s "Order information" check of each case.
 */

uses()->group('sandbox');

const SANDBOX_METHOD = 613;

beforeEach(function () {
    Sandbox::configure();
    config(['paynl.testmode' => true]);
    $this->paynl = app(PaynlService::class);
});

it('authenticates and lists the terminals of the account', function () {
    $terminals = $this->paynl->listTerminals();

    expect($terminals)->toBeArray()->each->toBeInstanceOf(Terminal::class);
    Sandbox::say(count($terminals).' terminal(s): '.collect($terminals)->map(fn ($t) => "{$t->code} {$t->status}/{$t->connectionStatus}")->join(', '));
});

it('passes certification case 1: minimum input', function () {
    $payment = $this->paynl->startOnlinePayment(101.01, 'TEST_DESCRIPTION', 'https://example.com/return', 'TESTREFERENCE', SANDBOX_METHOD);

    expect($payment->orderId)->not->toBeEmpty()
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->paymentUrl)->toStartWith('https://');
    Sandbox::say("Case 1: {$payment->paymentUrl}");
});

it('passes certification case 5: partner-integration code', function () {
    config(['paynl.stats.object' => 'TEST_PARTNER_VALUE']);

    $payment = app(PaynlService::class)->startOnlinePayment(505.05, 'TEST_DESCRIPTION_5', 'https://example.com/return', 'TESTREFERENCE5', SANDBOX_METHOD);

    expect($payment->orderId)->not->toBeEmpty()
        ->and($payment->paymentUrl)->toStartWith('https://');
    Sandbox::say("Case 5: {$payment->paymentUrl}");
});

it('reads the status of an order back', function () {
    $created = $this->paynl->startOnlinePayment(12.34, 'Status check', 'https://example.com/return', 'STATUS-'.time(), SANDBOX_METHOD);

    $payment = $this->paynl->getStatus($created->orderId);

    expect($payment->orderId)->toBe($created->orderId)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(12.34);
});

it('cancels a pending order and confirms it through a verified exchange', function () {
    Event::fake([PaymentStatusUpdated::class]);
    $created = $this->paynl->startOnlinePayment(5.00, 'Cancel check', 'https://example.com/return', 'CANCEL-'.time(), SANDBOX_METHOD);

    expect($this->paynl->cancel($created->orderId)->status)->toBe(PaymentStatus::Cancelled);

    // The exchange Pay. sends after a cancel; the package checks it with a status call before trusting it
    $this->post(route('paynl.exchange'), ['object' => [
        'orderId' => $created->orderId,
        'status' => ['code' => -90, 'action' => 'CANCEL'],
    ]])->assertOk()->assertSee('TRUE|', false);

    Event::assertDispatched(PaymentStatusUpdated::class, fn ($event) => $event->payment->orderId === $created->orderId
        && $event->payment->status === PaymentStatus::Cancelled);
});

it('rejects a forged exchange that claims an unknown order is paid', function () {
    Event::fake([PaymentStatusUpdated::class]);

    $this->post(route('paynl.exchange'), ['object' => [
        'orderId' => '59999999999X9999',
        'status' => ['code' => 100, 'action' => 'PAID'],
        'amount' => ['value' => 100000, 'currency' => 'EUR'],
    ]])->assertOk()->assertSee('FALSE|', false);

    Event::assertNotDispatched(PaymentStatusUpdated::class);
});

it('refuses a terminal payment to a terminal that is not on the account', function () {
    $this->paynl->startTerminalPayment(0.01, 'TH-0000-0000', 'Unknown terminal');
})->throws(PaynlException::class);
