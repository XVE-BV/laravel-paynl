<?php

use Illuminate\Support\Facades\Event;
use PayNL\Sdk\Model\Company;
use PayNL\Sdk\Model\Customer;
use PayNL\Sdk\Model\Order;
use PayNL\Sdk\Model\Products;
use PayNL\Sdk\Model\Terminal as PayTerminal;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\OrderRequest;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Support\Product;
use Xve\LaravelPaynl\Support\Terminal;

/*
 * What Pay. checks during the certification: the order data reaches Pay. as sent,
 * the terminal result and receipt reach the kassa, and refunds only go to terminals that can.
 */

function receiptLines(): array
{
    return [" Customer's receipt", ' ', "  Jasper's Bedrijf  ", 'Terminal: 8XDN6L    Merchant: 618088', 'Contactloze betaling', 'Maestro', 'Totaal: EUR 1,50'];
}

function terminalPayments(string $incidentCode = '0000', string $incidentText = 'AKKOORD'): array
{
    return [[
        'id' => '67f531c2-5114-8389-203a-051007856049',
        'paymentMethod' => ['id' => 1927, 'input' => ['terminalCode' => 'TH-1234-5678']],
        'supplierData' => [
            'incident_code' => $incidentCode,
            'incident_text' => $incidentText,
            'authorization_code' => 'F01914',
            'signature' => false,
            'ticket_data' => ['terminal' => '8XDN6L', 'merchant' => '618088', 'card_brand_label_name' => 'Maestro', 'transaction_amount' => 150],
        ],
    ]];
}

it('hands the kassa the receipt of a paid terminal payment', function () {
    $order = payOrder(100, 150)->setReceipt(base64_encode(json_encode(receiptLines())))->setPayments(terminalPayments());

    $payment = paynlWith(fn () => $order)->getStatus('EX-1234-5678-9012');

    expect($payment->isPaid())->toBeTrue()
        ->and($payment->failureReason)->toBeNull()
        ->and($payment->receipt->lines)->toBe(receiptLines())
        ->and($payment->receipt->text())->toContain('Maestro')
        ->and($payment->receipt->ticketData['terminal'])->toBe('8XDN6L')
        ->and($payment->receipt->authorizationCode)->toBe('F01914')
        ->and($payment->receipt->signatureRequired)->toBeFalse()
        ->and($payment->toArray()['receipt']['lines'])->toBe(receiptLines());
});

it('has no receipt before the terminal is done', function () {
    expect(paynlWith(fn () => payOrder(20))->getStatus('EX-1234-5678-9012')->receipt)->toBeNull();
});

it('flags a receipt the customer must sign', function () {
    $payments = terminalPayments();
    $payments[0]['supplierData']['signature'] = 1;

    $payment = paynlWith(fn () => payOrder(100)->setPayments($payments))->getStatus('EX-1234-5678-9012');

    expect($payment->receipt->signatureRequired)->toBeTrue();
});

it('carries the receipt of a signed exchange', function () {
    Event::fake([PaymentStatusUpdated::class]);
    exchangeUses(paynlWith(fn () => throw new RuntimeException('No status call expected for a signed exchange')));

    signedExchange(route('paynl.exchange'), exchangeObject([
        'receipt' => base64_encode(json_encode(receiptLines())),
        'payments' => terminalPayments(),
    ]))->assertJson(['result' => true]);

    Event::assertDispatched(PaymentStatusUpdated::class, fn ($event) => $event->payment->receipt?->lines === receiptLines());
});

it('keeps the incident text of a refused terminal payment as the failure reason', function () {
    $payment = paynlWith(fn () => payOrder(-90)->setPayments(terminalPayments('2620', 'GEANNULEERD')))->getStatus('EX-1234-5678-9012');

    expect($payment->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->failureReason)->toBe('GEANNULEERD');
});

it('sends the fields the SDK models leave out', function () {
    $sent = null;
    paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return payOrder(20);
    })->startOnlinePayment(30303, 'B2B', 'https://shop.test/done', configure: function (OrderRequest $request) {
        $request->setCustomer((new Customer)->setCompany((new Company)->setName('ACME')->setCoc('12345678')));
        $request->merge(['customer' => ['gaClientId' => 'GA-1', 'company' => ['country' => 'NL']], 'stats' => ['promotorId' => 123456789]]);
    });

    $body = $sent->getBodyParameters();

    expect($sent)->toBeInstanceOf(OrderRequest::class)
        ->and($body['customer'])->toMatchArray(['gaClientId' => 'GA-1'])
        ->and($body['customer']['company'])->toBe(['name' => 'ACME', 'cocNumber' => '12345678', 'country' => 'NL'])
        ->and($body['stats'])->toMatchArray(['object' => config('paynl.stats.object'), 'promotorId' => 123456789]);
});

it('sends product descriptions in UTF-8, not transliterated', function () {
    $sent = null;
    paynlWith(function ($request) use (&$sent) {
        $sent = $request;

        return payOrder(20);
    })->startOnlinePayment(90909, 'Dessert', 'https://shop.test/done', configure: function (OrderRequest $request) {
        $order = new Order;
        $order->setProducts((new Products)->addProduct(new Product('1', 'Crème brûlée Ø', 909.09, 'EUR', 'ARTICLE', 1)));
        $request->setOrder($order);
    });

    expect($sent->getBodyParameters()['order']['products'][0]['description'])->toBe('Crème brûlée Ø');
});

it('knows which terminals can refund to a card', function () {
    $withRefund = Terminal::fromPayTerminal((new PayTerminal)->setCode('TH-1')->setStatus('ACTIVE')->setPaymentTypes(['SALE', 'REFUND']));
    $saleOnly = Terminal::fromPayTerminal((new PayTerminal)->setCode('TH-2')->setStatus('ACTIVE')->setPaymentTypes(['SALE']));

    expect($withRefund->supportsRefund())->toBeTrue()
        ->and($saleOnly->supportsRefund())->toBeFalse()
        ->and($withRefund->toArray())->toMatchArray(['paymentTypes' => ['SALE', 'REFUND'], 'supportsRefund' => true]);
});

it('sends XVE\'s PartnerID as stats.object on every order without any setting in the app', function () {
    $sent = [];
    $paynl = paynlWith(function ($request) use (&$sent) {
        $sent[] = $request;

        return payOrder(20);
    });

    $paynl->startTerminalPayment(1210, 'TH-1234-5678', 'Kassa');
    $paynl->startTerminalRefund(500, 'TH-1234-5678', 'Retour');
    $paynl->startOnlinePayment(2500, 'Webshop', 'https://shop.test/done');

    expect(config('paynl.stats.object'))->toBe(PaynlService::PARTNER_ID)
        ->and(PaynlService::PARTNER_ID)->toBe('TEST_PARTNER_VALUE')
        ->and(array_map(fn ($request) => $request->getBodyParameters()['stats']['object'], $sent))->each->toBe(PaynlService::PARTNER_ID);
});
