<?php

use Illuminate\Support\Facades\Event;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\Payment;
use Xve\LaravelPaynl\Support\PaymentStatus;

beforeEach(function () {
    $this->paynl = Mockery::mock(PaynlService::class)->makePartial();
    app()->instance(PaynlService::class, $this->paynl);
});

function payment(PaymentStatus $status): Payment
{
    return new Payment('EX-1234-5678-9012', $status, 12.1);
}

it('dispatches the verified exchange and answers TRUE', function () {
    Event::fake([PaymentStatusUpdated::class]);
    $this->paynl->shouldReceive('handleExchange')->andReturn(payment(PaymentStatus::Paid));

    $this->post(route('paynl.exchange'), ['object' => ['orderId' => 'EX-1234-5678-9012']])
        ->assertOk()
        ->assertSee('TRUE|', false);

    Event::assertDispatched(PaymentStatusUpdated::class, fn ($event) => $event->payment->isPaid());
});

it('answers FALSE so Pay. retries when the exchange cannot be verified', function () {
    Event::fake([PaymentStatusUpdated::class]);
    $this->paynl->shouldReceive('handleExchange')->andThrow(new PaynlException('Signing request failed'));

    $this->post(route('paynl.exchange'), ['object' => []])
        ->assertOk()
        ->assertSee('FALSE|', false);

    Event::assertNotDispatched(PaymentStatusUpdated::class);
});
