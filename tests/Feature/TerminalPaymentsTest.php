<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Xve\LaravelPaynl\Contracts\PaymentGateway;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Events\TerminalPaymentPaid;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Exceptions\TerminalPaymentException;
use Xve\LaravelPaynl\Facades\Paynl;
use Xve\LaravelPaynl\Services\TerminalPayments;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Support\Receipt;
use Xve\LaravelPaynl\Support\Terminal;
use Xve\LaravelPaynl\Support\TerminalPayment;
use Xve\LaravelPaynl\Support\TerminalPaymentStatus;

beforeEach(function () {
    $this->paynl = Paynl::fake()->withTerminals(new Terminal('TH-0000-0000', 'Kassa', 'ACTIVE', 'ONLINE'));
    $this->terminal = app(TerminalPayments::class);
});

function startTracked(string $reference = 'KASSA1', array $context = ['sale' => 42]): TerminalPaymentStatus
{
    return test()->terminal->start(1210, 'TH-0000-0000', 'Kassa', $reference, $context);
}

function terminalStatus(PaymentStatus $status, int $statusCode = 0, ?string $failureReason = null, ?string $cancelledBy = null, ?Receipt $receipt = null): TerminalPaymentStatus
{
    $payment = new Payment('EX-1', $status, 1210, failureReason: $failureReason, statusCode: $statusCode, receipt: $receipt);

    return TerminalPaymentStatus::for($payment, $cancelledBy ? new TerminalPayment('EX-1', 'KASSA1', 'TH-0000-0000', 1210, cancelledBy: $cancelledBy) : null, 1000);
}

it('starts a terminal payment and keeps what to book for it', function () {
    $status = startTracked();

    $this->paynl->assertPaymentStarted(fn (array $started) => $started['type'] === 'terminal'
        && $started['amount'] === 1210
        && $started['terminalCode'] === 'TH-0000-0000'
        && $started['reference'] === 'KASSA1');
    expect($status->toArray())->toMatchArray(['status' => 'pending', 'final' => false, 'pollAfterMs' => 3000, 'message' => 'Waiting for the payment on the terminal...'])
        ->and($this->terminal->find($status->payment->orderId))->toMatchObject([
            'reference' => 'KASSA1',
            'terminal' => 'TH-0000-0000',
            'amount' => 1210,
            'context' => ['sale' => 42],
        ]);
});

it('follows the order it started when the same reference comes again', function () {
    $first = startTracked();

    expect(startTracked()->payment->orderId)->toBe($first->payment->orderId)
        ->and($this->paynl->started())->toHaveCount(1);
});

it('does not start a payment that is being started already', function () {
    Cache::lock('paynl:terminal:start:KASSA1', 30)->get();

    expect(fn () => startTracked())->toThrow(TerminalPaymentException::class, 'This payment is already being started on the terminal.');
    $this->paynl->assertNothingStarted();
});

it('does not start a payment on a terminal that cannot take it', function (Terminal $terminal, string $message) {
    $this->paynl->withTerminals($terminal);

    expect(fn () => startTracked())->toThrow(TerminalPaymentException::class, $message);
    $this->paynl->assertNothingStarted();
})->with([
    'offline' => [new Terminal('TH-0000-0000', 'Kassa', 'ACTIVE', 'OFFLINE'), 'The terminal cannot be reached.'],
    'inactive' => [new Terminal('TH-0000-0000', 'Kassa', 'INACTIVE', 'ONLINE'), 'The terminal is not active (inactive).'],
    'unknown' => [new Terminal('TH-9999-9999', 'Other', 'ACTIVE', 'ONLINE'), 'Pay.nl does not know this terminal.'],
]);

it('says the terminal is busy when Pay. refuses the payment', function () {
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('listTerminals')->andReturn([new Terminal('TH-0000-0000', 'Kassa', 'ACTIVE', 'ONLINE')]);
    $gateway->shouldReceive('startTerminalPayment')->andThrow(new PaynlException('Terminal in use'));
    app()->instance(PaymentGateway::class, $gateway);

    try {
        app(TerminalPayments::class)->start(1210, 'TH-0000-0000', 'Kassa', 'KASSA1');
    } catch (TerminalPaymentException $e) {
    }

    expect($e->status)->toBe(409)
        ->and($e->getMessage())->toContain('busy with another payment or refuses it (Terminal in use)')
        ->and(Cache::has('paynl:terminal:reference:KASSA1'))->toBeFalse();
});

it('gives the kassa one message per way a terminal payment ends', function (TerminalPaymentStatus $status, string $message) {
    expect($status->message())->toBe($message);
})->with([
    'paid' => [fn () => terminalStatus(PaymentStatus::Paid), 'Paid on the terminal. Offer the customer the terminal receipt.'],
    'paid, to sign' => [fn () => terminalStatus(PaymentStatus::Paid, receipt: new Receipt(['OK'], signatureRequired: true)), 'Paid on the terminal. Have the customer sign the merchant copy and offer the terminal receipt.'],
    'pending' => [fn () => terminalStatus(PaymentStatus::Pending), 'Waiting for the payment on the terminal...'],
    'verify' => [fn () => terminalStatus(PaymentStatus::Verify), 'Waiting for pay.nl to confirm...'],
    'cancelled from the kassa' => [fn () => terminalStatus(PaymentStatus::Cancelled, -90, 'STOP', TerminalPayment::CANCELLED_BY_POS), 'The payment was cancelled from the till. Try again to start a new payment.'],
    'kassa timeout' => [fn () => terminalStatus(PaymentStatus::Cancelled, -90, cancelledBy: TerminalPayment::CANCELLED_BY_TIMEOUT), 'The payment expired: nothing was paid on the terminal. Try again to start a new payment.'],
    'expired at Pay.' => [fn () => terminalStatus(PaymentStatus::Cancelled, -80), 'The payment expired on the terminal: the customer did not pay in time. Try again.'],
    'card declined' => [fn () => terminalStatus(PaymentStatus::Cancelled, -63, 'Card blocked'), 'The card was declined (Card blocked). Try again or choose another payment method.'],
    'stopped by the customer' => [fn () => terminalStatus(PaymentStatus::Cancelled, -90, 'Cancelled by customer'), 'The payment was stopped on the terminal: Cancelled by customer. Try again.'],
    'stopped without a reason' => [fn () => terminalStatus(PaymentStatus::Cancelled, -90), 'The payment was stopped on the terminal. Try again.'],
    'refunded' => [fn () => terminalStatus(PaymentStatus::Refunded), 'The payment was refunded to the customer.'],
]);

it('has every terminal message in Dutch too', function () {
    $en = require __DIR__.'/../../resources/lang/en/terminal.php';
    $nl = require __DIR__.'/../../resources/lang/nl/terminal.php';

    expect(array_keys($nl))->toBe(array_keys($en));
});

it('tells the kassa to stop polling once Pay. is done with the order', function () {
    $status = startTracked();
    $this->paynl->markAs($status->payment->orderId, PaymentStatus::Cancelled);

    expect($this->terminal->status($status->payment->orderId)->toArray())->toMatchArray(['final' => true, 'pollAfterMs' => null]);
});

it('polls at most once a second while the payment runs', function () {
    $status = startTracked();

    expect($this->terminal->status($status->payment->orderId)->pollAfterMs)->toBe(1000);
});

it('stops a payment nobody paid within the timeout', function () {
    $orderId = startTracked()->payment->orderId;

    $this->travel(181)->seconds();
    $status = $this->terminal->status($orderId);

    expect($status->toArray())->toMatchArray(['status' => 'cancelled', 'final' => true])
        ->and($status->message())->toStartWith('The payment expired: nothing was paid')
        ->and($this->terminal->find($orderId)->cancelledBy)->toBe(TerminalPayment::CANCELLED_BY_TIMEOUT);
});

it('stops asking when Pay. has no final answer long after the timeout', function () {
    $orderId = startTracked()->payment->orderId;
    Cache::put('paynl:terminal:order:'.$orderId, $this->terminal->find($orderId)->cancelledBy(TerminalPayment::CANCELLED_BY_TIMEOUT)->toArray());

    $this->travel(241)->seconds();
    $status = $this->terminal->status($orderId);

    expect($status->toArray())->toMatchArray(['status' => 'pending', 'final' => false, 'pollAfterMs' => null])
        ->and($status->message())->toStartWith('The terminal gave no final answer.');
});

it('remembers a payment was cancelled from the kassa', function () {
    $orderId = startTracked()->payment->orderId;

    $status = $this->terminal->cancel($orderId);

    expect($status->isFinal())->toBeTrue()
        ->and($status->message())->toStartWith('The payment was cancelled from the till.');
});

it('still reports the status when the cancel fails', function () {
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('cancel')->andThrow(new PaynlException('Already paid'));
    $gateway->shouldReceive('getStatus')->andReturn(new Payment('EX-1', PaymentStatus::Paid, 1210));
    app()->instance(PaymentGateway::class, $gateway);

    expect(app(TerminalPayments::class)->cancel('EX-1')->toArray())->toMatchArray(['status' => 'paid', 'final' => true]);
});

it('books a paid terminal payment once and gives a second call the same answer', function () {
    $orderId = startTracked()->payment->orderId;
    $this->paynl->markAs($orderId, PaymentStatus::Paid);
    $bookings = 0;
    $book = function (Payment $payment, TerminalPayment $terminalPayment) use (&$bookings) {
        $bookings++;

        return ['documentId' => 7, 'context' => $terminalPayment->context];
    };

    $first = $this->terminal->book($orderId, 1210, $book);
    $second = $this->terminal->book($orderId, 1210, $book);

    expect($bookings)->toBe(1)
        ->and($first)->toBe(['documentId' => 7, 'context' => ['sale' => 42]])
        ->and($second)->toBe($first)
        ->and($this->terminal->isBooked($orderId))->toBeTrue();
});

it('answers from the app\'s own records when the cache no longer knows the booking', function () {
    $orderId = startTracked()->payment->orderId;
    $this->paynl->markAs($orderId, PaymentStatus::Paid);
    Cache::flush();

    $answer = $this->terminal->book($orderId, 1210, fn () => throw new RuntimeException('Booked twice'), fn () => ['documentId' => 7]);

    expect($answer)->toBe(['documentId' => 7]);
});

it('books when the app\'s own records do not have it yet', function () {
    $orderId = startTracked()->payment->orderId;
    $this->paynl->markAs($orderId, PaymentStatus::Paid);

    expect($this->terminal->book($orderId, 1210, fn () => 'booked', fn () => null))->toBe('booked');
});

it('only books terminal payments it started', function (?string $orderId) {
    $this->paynl->markAs('EX-9999', PaymentStatus::Paid, 1210);

    expect(fn () => $this->terminal->book($orderId, 1210, fn () => 'booked'))->toThrow(TerminalPaymentException::class, 'Start the payment on the terminal first.');
})->with([
    'no order' => [null],
    'an order it did not start' => ['EX-9999'],
]);

it('does not book a terminal payment Pay. did not confirm', function (PaymentStatus $status, int $amount, string $message) {
    $orderId = startTracked()->payment->orderId;
    $this->paynl->markAs($orderId, $status, $amount);

    expect(fn () => $this->terminal->book($orderId, 1210, fn () => 'booked'))->toThrow(TerminalPaymentException::class, $message);
    expect($this->terminal->isBooked($orderId))->toBeFalse();
})->with([
    'not paid' => [PaymentStatus::Cancelled, 1210, 'The payment on the terminal did not succeed.'],
    'another amount' => [PaymentStatus::Paid, 100, 'The amount paid on the terminal does not match the amount due.'],
]);

it('does not book while another booking of the same order runs', function () {
    Sleep::fake(syncWithCarbon: true);
    $orderId = startTracked()->payment->orderId;
    $this->paynl->markAs($orderId, PaymentStatus::Paid);
    Cache::lock('paynl:terminal:book:'.$orderId, 60)->get();

    expect(fn () => $this->terminal->book($orderId, 1210, fn () => 'booked'))->toThrow(TerminalPaymentException::class, 'already being booked');
});

it('does not count a refused or failed booking as booked', function () {
    $orderId = startTracked()->payment->orderId;
    $this->paynl->markAs($orderId, PaymentStatus::Paid);

    $refused = $this->terminal->book($orderId, 1210, fn () => new JsonResponse(['message' => 'Period closed'], 409));
    expect($refused->getStatusCode())->toBe(409)
        ->and($this->terminal->isBooked($orderId))->toBeFalse();

    expect(fn () => $this->terminal->book($orderId, 1210, fn () => throw new RuntimeException('Invoice failed')))->toThrow(RuntimeException::class);
    expect($this->terminal->isBooked($orderId))->toBeFalse();
});

it('does not book when Pay. is not configured', function () {
    $orderId = startTracked()->payment->orderId;
    app()->forgetInstance(PaymentGateway::class);
    config(['paynl.token_code' => null]);

    expect(fn () => app(TerminalPayments::class)->book($orderId, 1210, fn () => 'booked'))
        ->toThrow(TerminalPaymentException::class, 'Pay.nl is not configured');
});

it('answers a refused terminal payment as JSON with the message for the cashier', function () {
    $response = TerminalPaymentException::booking()->render();

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toBe(['message' => 'This terminal payment is already being booked.']);
});

it('asks the app to book a paid terminal payment the kassa did not, as the cashier who started it', function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->timestamps();
    });
    $cashier = User::forceCreate(['name' => 'Kassa', 'email' => 'kassa@xve.be', 'password' => 'x']);
    $this->actingAs($cashier);
    $orderId = startTracked()->payment->orderId;
    Auth::forgetUser();
    $booked = [];
    Event::listen(TerminalPaymentPaid::class, function (TerminalPaymentPaid $event) use (&$booked) {
        $booked[] = [$event->terminalPayment->context, Auth::id()];
    });

    PaymentStatusUpdated::dispatch($this->paynl->markAs($orderId, PaymentStatus::Paid));

    expect($booked)->toBe([[['sale' => 42], $cashier->id]])
        ->and(Auth::user())->toBeNull();
});

it('leaves exchange calls alone that are not paid, not started here or booked already', function () {
    $orderId = startTracked()->payment->orderId;
    $booked = 0;
    Event::listen(TerminalPaymentPaid::class, function () use (&$booked) {
        $booked++;
    });

    PaymentStatusUpdated::dispatch($this->paynl->markAs($orderId, PaymentStatus::Pending));
    PaymentStatusUpdated::dispatch(new Payment('EX-9999', PaymentStatus::Paid, 1210));
    Cache::put('paynl:terminal:booked:'.$orderId, 'booked');
    PaymentStatusUpdated::dispatch($this->paynl->markAs($orderId, PaymentStatus::Paid));

    expect($booked)->toBe(0);
});

it('prints a merchant copy to sign when the terminal asks for a signature', function () {
    expect((new Receipt(['CARD', 'EUR 12,10']))->printLines())->toBe(['CARD', 'EUR 12,10'])
        ->and((new Receipt(['CARD', 'EUR 12,10'], signatureRequired: true))->printLines())->toBe([
            'CARD', 'EUR 12,10', '', '--- Merchant copy ---', 'CARD', 'EUR 12,10', '', 'Customer signature:', '', '', '______________________',
        ]);
});
