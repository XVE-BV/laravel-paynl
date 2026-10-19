<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Support\PaymentStatus;

function exchangeObject(array $overrides = []): array
{
    return array_replace([
        'id' => '9a8b7c6d-0000-0000-0000-000000000000',
        'orderId' => '51234567890X1234',
        'reference' => 'ORD-1',
        'status' => ['code' => 100, 'action' => 'PAID'],
        'amount' => ['value' => 1210, 'currency' => 'EUR'],
    ], $overrides);
}

/**
 * Posts an exchange the way Pay. does for SIGNED_JSON_POST: HMAC over the raw body, keyed by the token code.
 */
function signedExchange(string $url, array $object, string $keyId = 'AT-0000-0000', string $secret = 'secret', string $algorithm = 'sha256', ?string $signature = null): TestResponse
{
    $body = json_encode(['event' => 'status_changed', 'type' => 'order', 'version' => 1, 'object' => $object]);

    return test()->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_SIGNATURE_METHOD' => 'HMAC',
        'HTTP_SIGNATURE_ALGORITHM' => $algorithm,
        'HTTP_SIGNATURE_KEYID' => $keyId,
        'HTTP_SIGNATURE' => $signature ?? hash_hmac($algorithm, $body, $secret),
    ], $body);
}

it('trusts a correctly signed exchange without calling Pay.', function () {
    Event::fake([PaymentStatusUpdated::class]);
    exchangeUses(paynlWith(fn () => throw new RuntimeException('No status call expected for a signed exchange')));

    signedExchange(route('paynl.exchange'), exchangeObject())
        ->assertOk()
        ->assertJson(['result' => true]);

    Event::assertDispatched(PaymentStatusUpdated::class, fn ($event) => $event->payment->isPaid()
        && $event->payment->orderId === '51234567890X1234'
        && $event->payment->amount === 1210
        && $event->payment->reference === 'ORD-1');
});

it('accepts an exchange signed with the sales location secret', function () {
    Event::fake([PaymentStatusUpdated::class]);
    signedExchange(route('paynl.exchange'), exchangeObject(), 'SL-0000-0000', 'sl-secret', 'sha512')
        ->assertJson(['result' => true]);

    Event::assertDispatchedTimes(PaymentStatusUpdated::class, 1);
});

it('answers FALSE so Pay. retries when the signature cannot be verified', function (string $url, string $keyId, string $secret, string $algorithm, ?string $signature) {
    Event::fake([PaymentStatusUpdated::class]);
    signedExchange($url, exchangeObject(), $keyId, $secret, $algorithm, $signature)
        ->assertOk()
        ->assertJson(['result' => false]);

    Event::assertNotDispatched(PaymentStatusUpdated::class);
})->with([
    'wrong secret' => fn () => [route('paynl.exchange'), 'AT-0000-0000', 'not-the-secret', 'sha256', null],
    'tampered signature' => fn () => [route('paynl.exchange'), 'AT-0000-0000', 'secret', 'sha256', str_repeat('0', 64)],
    'key of another account' => fn () => [route('paynl.exchange'), 'AT-1111-1111', 'secret', 'sha256', null],
    'unsupported algorithm' => fn () => [route('paynl.exchange'), 'AT-0000-0000', 'secret', 'md5', null],
]);

it('asks Pay. for the status of an unsigned exchange instead of trusting its payload', function () {
    Event::fake([PaymentStatusUpdated::class]);
    $asked = null;
    exchangeUses(paynlWith(function ($request) use (&$asked) {
        $asked = $request->getPathParameters();

        return payOrder(-90, 1210, '51234567890X1234');
    }));

    // The payload claims paid; Pay. says cancelled
    $this->post(route('paynl.exchange'), ['object' => exchangeObject()])
        ->assertOk()
        ->assertSee('TRUE|', false);

    expect(array_values($asked))->toContain('51234567890X1234');
    Event::assertDispatched(PaymentStatusUpdated::class, fn ($event) => $event->payment->status === PaymentStatus::Cancelled);
});

it('reads the order id of a legacy (refund) exchange', function () {
    Event::fake([PaymentStatusUpdated::class]);
    exchangeUses(paynlWith(fn () => payOrder(-81, 1210, 'EX-1234-5678-9012')));

    $this->post(route('paynl.exchange'), ['action' => 'refund:received', 'order_id' => 'EX-1234-5678-9012', 'object' => ''])
        ->assertSee('TRUE|', false);

    Event::assertDispatched(PaymentStatusUpdated::class, fn ($event) => $event->payment->status === PaymentStatus::Refunded);
});

it('rejects an unsigned exchange without an order id', function () {
    Event::fake([PaymentStatusUpdated::class]);
    exchangeUses(paynlWith(fn () => throw new RuntimeException('No status call expected')));

    $this->post(route('paynl.exchange'), ['object' => ['status' => ['code' => 20]]])
        ->assertSee('FALSE|', false);

    Event::assertNotDispatched(PaymentStatusUpdated::class);
});

it('answers FALSE when a listener fails, so Pay. retries', function () {
    Event::listen(PaymentStatusUpdated::class, fn () => throw new RuntimeException('Booking failed'));

    signedExchange(route('paynl.exchange'), exchangeObject())
        ->assertJson(['result' => false]);
});
