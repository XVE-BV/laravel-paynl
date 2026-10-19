<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PayNL\Sdk\Http\GuzzleClientFactoryInterface;
use PayNL\Sdk\Model\Request\OrderCaptureRequest;
use PayNL\Sdk\Model\Request\OrderCreateRequest;
use PayNL\Sdk\Model\Request\OrderStatusRequest;
use PayNL\Sdk\Model\Request\TerminalsBrowseRequest;
use PayNL\Sdk\Model\Request\TransactionRefundRequest;
use PayNL\Sdk\Model\Terminal as PayTerminal;
use PayNL\Sdk\Model\Terminals;
use Xve\LaravelPaynl\Exceptions\PaymentNotVerifiedException;
use Xve\LaravelPaynl\Exceptions\PaynlException;
use Xve\LaravelPaynl\Http\Client\TimeoutGuzzleClientFactory;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\PaymentStatus;

it('sends a terminal payment to the chosen terminal with the stats fields', function () {
    config(['paynl.stats.object' => 'TEST_PARTNER_VALUE', 'paynl.stats.tool' => 'Nassau']);
    $sent = null;

    $payment = paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return payOrder(20);
    })->startTerminalPayment(1210, 'TH-1111-2222', 'Kassa');

    expect($sent)->toBeInstanceOf(OrderCreateRequest::class);
    $body = $sent->getBodyParameters();
    expect($body['paymentMethod']['id'])->toBe(PaynlService::TERMINAL_PAYMENT_METHOD)
        ->and($body['paymentMethod']['input']['terminalCode'])->toBe('TH-1111-2222')
        ->and($body['serviceId'])->toBe('SL-0000-0000')
        ->and($body['amount'])->toBe(['value' => 1210, 'currency' => 'EUR'])
        ->and($body['stats']['object'])->toBe('TEST_PARTNER_VALUE')
        ->and($body['stats']['tool'])->toBe('Nassau')
        ->and($body['exchangeUrl'])->toBe(route('paynl.exchange'))
        ->and($payment->orderId)->toBe('EX-1234-5678-9012')
        ->and($payment->amount)->toBe(1210)
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('sends a terminal refund with the POS refund method', function () {
    $sent = null;

    paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return payOrder(20);
    })->startTerminalRefund(500, 'TH-1111-2222', 'Retour');

    expect($sent->getBodyParameters()['paymentMethod']['id'])->toBe(PaynlService::TERMINAL_REFUND_METHOD);
});

it('starts an online payment and hands back the payment url', function () {
    $order = payOrder(20)->setLinks(['redirect' => 'https://pay.nl/checkout/abc', 'status' => 'https://connect.pay.nl/v1/orders/abc/status']);

    $payment = paynlWith(fn () => $order)->startOnlinePayment(2500, 'Webshop', 'https://shop.test/done', 'ORD-1');

    expect($payment->paymentUrl)->toBe('https://pay.nl/checkout/abc')
        ->and($payment->statusUrl)->toBe('https://connect.pay.nl/v1/orders/abc/status');
});

it('lets the caller add order data before it is sent', function () {
    $sent = null;

    paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return payOrder(20);
    })->startOnlinePayment(2500, 'Webshop', 'https://shop.test/done', configure: fn (OrderCreateRequest $request) => $request->setDescription('Changed'));

    expect($sent->getBodyParameters()['description'])->toBe('Changed');
});

it('refuses amounts and references Pay. would refuse', function (int $amount, string $reference) {
    paynlWith(fn () => payOrder(20))->startOnlinePayment($amount, 'Webshop', 'https://shop.test/done', $reference);
})->with([
    'zero amount' => [0, 'ORD-1'],
    'negative amount' => [-100, 'ORD-1'],
    'underscore in reference' => [100, 'ORD_1'],
    'reference too long' => [100, str_repeat('A', 129)],
])->throws(InvalidArgumentException::class);

it('normalises the Pay. status codes', function (int $code, PaymentStatus $status) {
    $payment = paynlWith(fn ($request) => $request instanceof OrderStatusRequest ? payOrder($code) : null)->getStatus('EX-1234-5678-9012');

    expect($payment->status)->toBe($status)
        ->and($payment->statusCode)->toBe($code);
})->with([
    'paid' => [100, PaymentStatus::Paid],
    'pending' => [20, PaymentStatus::Pending],
    'partial payment' => [80, PaymentStatus::Pending],
    'verify' => [85, PaymentStatus::Verify],
    'authorized' => [95, PaymentStatus::Authorized],
    'cancelled' => [-90, PaymentStatus::Cancelled],
    'expired' => [-80, PaymentStatus::Cancelled],
    'denied' => [-63, PaymentStatus::Cancelled],
    'partially refunded' => [-82, PaymentStatus::PartiallyRefunded],
    'refunded' => [-81, PaymentStatus::Refunded],
    'chargeback' => [-71, PaymentStatus::Chargeback],
    'unknown' => [12345, PaymentStatus::Pending],
]);

it('only calls a status final once Pay. is done with the order', function () {
    expect(PaymentStatus::Paid->isFinal())->toBeTrue()
        ->and(PaymentStatus::Cancelled->isFinal())->toBeTrue()
        ->and(PaymentStatus::Pending->isFinal())->toBeFalse()
        ->and(PaymentStatus::Verify->isFinal())->toBeFalse()
        ->and(PaymentStatus::Authorized->isFinal())->toBeFalse();
});

it('carries the reason the terminal stopped the payment', function () {
    $order = payOrder(-90)->setPayments([
        ['id' => 'PA-1', 'supplierData' => ['incident_code' => '2620', 'incident_text' => 'Cancelled by user']],
    ]);

    $payment = paynlWith(fn () => $order)->getStatus('EX-1234-5678-9012');

    expect($payment->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->failureReason)->toBe('Cancelled by user')
        ->and(paynlWith(fn () => payOrder(100))->getStatus('EX-1234-5678-9012')->failureReason)->toBeNull();
});

it('verifies a payment that is paid for the expected amount', function () {
    $payment = paynlWith(fn () => payOrder(100, 1210))->verifyPaid('EX-1234-5678-9012', 1210);

    expect($payment->isPaid())->toBeTrue()
        ->and($payment->amount)->toBe(1210);
});

it('does not verify a payment that is not paid', function () {
    paynlWith(fn () => payOrder(-90))->verifyPaid('EX-1234-5678-9012', 1210);
})->throws(PaymentNotVerifiedException::class, 'not paid');

it('does not verify a payment paid for another amount', function () {
    try {
        paynlWith(fn () => payOrder(100, 1209))->verifyPaid('EX-1234-5678-9012', 1210);
    } catch (PaymentNotVerifiedException $e) {
        expect($e->reason)->toBe(PaymentNotVerifiedException::AMOUNT_MISMATCH)
            ->and($e->payment->amount)->toBe(1209)
            ->and($e->getMessage())->toContain('12.09')->toContain('12.10');

        return;
    }

    $this->fail('A payment for another amount was verified.');
});

it('refunds part of an order and asks Pay. to report it on the exchange', function () {
    $sent = null;

    paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return null;
    })->refund('EX-1234-5678-9012', 500, 'Retour');

    expect($sent)->toBeInstanceOf(TransactionRefundRequest::class)
        ->and($sent->getBodyParameters())->toMatchArray([
            'amount' => ['value' => 500, 'currency' => 'EUR'],
            'description' => 'Retour',
            'exchangeUrl' => route('paynl.exchange'),
        ]);
});

it('captures an authorized order for an amount in cents', function () {
    $sent = [];

    paynlWith(function ($request) use (&$sent) {
        $sent[] = $request;

        return payOrder(100);
    })->capture('EX-1234-5678-9012', 1210);

    expect($sent[0])->toBeInstanceOf(OrderCaptureRequest::class)
        ->and($sent[0]->getBodyParameters()['amount'])->toBe(1210)
        ->and($sent[1])->toBeInstanceOf(OrderStatusRequest::class);
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
})->throws(PaynlException::class, 'not configured');

it('calls Pay. without the SDK status cache and with a timeout', function () {
    config(['paynl.timeout' => 7, 'paynl.core' => 'https://connect.payments.nl']);
    $service = app(PaynlService::class);

    $config = (fn () => $this->payConfig())->call($service);
    $factory = $config->get('guzzle_client_factory');
    $client = $factory->create([]);

    expect($config->isCacheEnabled())->toBeFalse()
        ->and($config->getCore())->toBe('https://connect.payments.nl')
        ->and($factory)->toBeInstanceOf(TimeoutGuzzleClientFactory::class)->toBeInstanceOf(GuzzleClientFactoryInterface::class)
        ->and($client->getConfig('timeout'))->toBe(7.0)
        ->and($client->getConfig('connect_timeout'))->toBe(3.0);
});

it('asks the status of an order on the configured core', function (?string $core, string $orderId, string $host) {
    config(['paynl.core' => $core]);
    $request = new OrderStatusRequest($orderId);
    $config = (fn () => $this->payConfig($request))->call(app(PaynlService::class));

    // Swap the network for a recorder, keeping everything else the SDK does with the config
    $sent = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{}')]));
    $stack->push(Middleware::history($sent));
    $config->setGuzzleClientFactory(new class($stack) implements GuzzleClientFactoryInterface
    {
        public function __construct(private HandlerStack $stack) {}

        public function create(array $config): Client
        {
            return new Client(array_merge($config, ['handler' => $this->stack]));
        }
    });

    try {
        $request->setConfig($config)->start();
    } catch (Throwable) {
        // The empty answer does not parse; only the url matters here
    }

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['request']->getUri()->getHost())->toBe($host);
})->with([
    'TGU order on the configured core' => ['https://connect.payments.nl', '51234567890X1234', 'connect.payments.nl'],
    'TGU order without a core' => [null, '51234567890X1234', 'connect.pay.nl'],
    'legacy (EX-) order stays on rest.pay.nl' => ['https://connect.payments.nl', 'EX-1234-5678-9012', 'rest.pay.nl'],
]);
