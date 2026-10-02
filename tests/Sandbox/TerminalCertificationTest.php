<?php

use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Tests\Sandbox\Sandbox;

/*
 * The POS scenarios Pay. checks for a terminal certification, against a real terminal on the
 * test account (PAYNL_SANDBOX_TERMINAL). Some need someone at the terminal: the test prints what
 * to do and then polls the status the way a POS does. Run them one at a time with --filter, e.g.
 *
 *   composer test:terminal -- --filter="scenario 1"
 *
 * For every scenario, note the status and failureReason it prints: that is the message the
 * kassa screen must show, and what Pay. wants to see during the certification.
 */

uses()->group('terminal');

beforeEach(function () {
    Sandbox::configure();
    $this->terminal = Sandbox::terminal();
    $this->paynl = app(PaynlService::class);
});

function startOnTerminal(string $scenario): Payment
{
    $terminal = Sandbox::terminal();
    $payment = app(PaynlService::class)->startTerminalPayment(Sandbox::terminalAmount(), $terminal, "Certification {$scenario}", 'CERT-'.time());
    Sandbox::say("{$scenario}: order {$payment->orderId} sent to {$terminal}");

    return $payment;
}

function reportTerminalResult(Payment $payment): void
{
    Sandbox::say("Result: {$payment->status->value}".($payment->failureReason ? " ({$payment->failureReason})" : ''));
}

it('finds the test terminal active and online', function () {
    $terminal = collect($this->paynl->listTerminals())->firstWhere('code', $this->terminal);

    expect($terminal)->not->toBeNull("{$this->terminal} is not on this account")
        ->and($terminal->isUsable())->toBeTrue("{$this->terminal} is {$terminal?->status}/{$terminal?->connectionStatus}");
});

it('scenario 1: completes a successful card payment', function () {
    $payment = startOnTerminal('scenario 1');
    Sandbox::say('Pay with a (test) card on the terminal now.');

    $result = Sandbox::waitForFinalStatus($payment->orderId);
    reportTerminalResult($result);

    expect($result->status)->toBe(PaymentStatus::Paid);
});

it('scenario 2: reports a payment the customer stops on the terminal', function () {
    $payment = startOnTerminal('scenario 2');
    Sandbox::say('Press the STOP (red) button on the terminal now.');

    $result = Sandbox::waitForFinalStatus($payment->orderId);
    reportTerminalResult($result);

    expect($result->status)->toBe(PaymentStatus::Cancelled);
});

it('scenario 3: reports a payment that expires on the terminal', function () {
    $payment = startOnTerminal('scenario 3');
    Sandbox::say('Do NOT touch the terminal; waiting for it to time out.');

    $result = Sandbox::waitForFinalStatus($payment->orderId, (int) Sandbox::env('PAYNL_SANDBOX_TIMEOUT', '300'));
    reportTerminalResult($result);

    expect($result->status)->toBe(PaymentStatus::Cancelled);
});

it('scenario 4: refuses a second payment while the terminal is busy', function () {
    $first = startOnTerminal('scenario 4 (first)');

    try {
        $second = startOnTerminal('scenario 4 (second)');
        $result = Sandbox::waitForFinalStatus($second->orderId, 60);
        reportTerminalResult($result);
        expect($result->status)->toBe(PaymentStatus::Cancelled);
    } catch (PaynlException $e) {
        Sandbox::say("Second payment refused: {$e->getMessage()}");
        expect($e->getMessage())->not->toBeEmpty();
    } finally {
        $this->paynl->cancel($first->orderId);
    }
});

it('scenario 5: reports a payment to a terminal without connection', function () {
    Sandbox::say('Unplug the network cable / turn off wifi of the terminal, then wait 30 seconds.');
    sleep(30);

    try {
        $payment = startOnTerminal('scenario 5');
        $result = Sandbox::waitForFinalStatus($payment->orderId);
        reportTerminalResult($result);
        expect($result->status)->toBe(PaymentStatus::Cancelled);
    } catch (PaynlException $e) {
        Sandbox::say("Payment refused: {$e->getMessage()}");
        expect($e->getMessage())->not->toBeEmpty();
    }

    Sandbox::say('Reconnect the terminal.');
});

it('cancels a terminal payment from the POS', function () {
    $payment = startOnTerminal('POS cancel');
    sleep(5);

    $this->paynl->cancel($payment->orderId);
    $result = Sandbox::waitForFinalStatus($payment->orderId, 60);
    reportTerminalResult($result);

    expect($result->status)->toBe(PaymentStatus::Cancelled);
});
