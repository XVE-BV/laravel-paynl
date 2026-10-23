<?php

use Illuminate\Support\Facades\Event;
use PayNL\Sdk\Model\Address;
use PayNL\Sdk\Model\Company;
use PayNL\Sdk\Model\Customer;
use PayNL\Sdk\Model\Order;
use PayNL\Sdk\Model\Products;
use Xve\LaravelPaynl\Events\PaymentStatusUpdated;
use Xve\LaravelPaynl\Services\PaynlService;
use Xve\LaravelPaynl\Support\OrderRequest;
use Xve\LaravelPaynl\Support\PaymentStatus;
use Xve\LaravelPaynl\Support\Product;
use Xve\LaravelPaynl\Support\Terminal;
use Xve\LaravelPaynl\Tests\Sandbox\Sandbox;

/*
 * Runs against the real Pay. API with test credentials, without anyone at a terminal.
 * Every order is a sandbox order (payment method 613 and/or integration.test), so no money moves.
 * The certification cases read Pay.'s own check of every field from the sandbox payment page.
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
    Sandbox::say(count($terminals).' terminal(s): '.collect($terminals)->map(fn ($t) => "{$t->code} {$t->status}/{$t->connectionStatus} [".implode(', ', $t->paymentTypes).']')->join(', '));
});

/*
 * Pay.'s data integrity test cases (developer.pay.nl/docs/testing-your-integration). Pay. picks the case
 * from the amount, and its sandbox page shows per field whether it passed; the test reads that page.
 * Fields the SDK models send are set through them, the rest through OrderRequest::merge().
 */
dataset('certification cases', [
    'case 1: minimum input' => [1, 10101, 'TEST_DESCRIPTION_1', 'TESTREFERENCE1', null],

    'case 2: delivery & invoice addresses' => [2, 20202, 'TEST_DESCRIPTION_2', 'TESTREFERENCE2', function (OrderRequest $request) {
        $order = new Order;
        $order->setDeliveryAddress(address('TEST_DELIVERY_STREET', '101', 'a, first floor', '1122AB', 'TEST_DELIVERY_CITY', 'NL', 'TEST_DELIVERY_REGION'));
        $order->setInvoiceAddress(address('TEST_INVOICE_STREET', '202', 'b, second floor', '2233', 'TEST_INVOICE_CITY', 'BE', 'TEST_INVOICE_REGION'));
        $request->setOrder($order);
    }],

    // Pay.'s sandbox checks TESTREFERENCE2 here, not the TESTREFERENCE3 of its sample body
    'case 3: B2B order' => [3, 30303, 'TEST_DESCRIPTION_3', 'TESTREFERENCE2', function (OrderRequest $request) {
        $company = (new Company)->setName('TEST_COMPANY_NAME')->setCoc('12345678')->setVat('NL0123456789');
        $request->setCustomer((new Customer)->setCompany($company));
        // The SDK sends the company country as countryCode; Pay. reads country
        $request->merge(['customer' => ['company' => ['country' => 'NL']]]);
    }],

    // And TESTREFERENCE without a number here
    'case 4: Belgian customer' => [4, 40404, 'TEST_DESCRIPTION_4', 'TESTREFERENCE', function (OrderRequest $request) {
        $order = new Order;
        $order->setInvoiceAddress((new Address)->setCountryCode('BE')->setZipCode('1122'));
        $request->setOrder($order);
    }],

    // Pay.'s fixed test value, until Pay. hands out the real PartnerID
    'case 5: partner-integration code' => [5, 50505, 'TEST_DESCRIPTION_5', 'TESTREFERENCE5', null, ['paynl.stats.object' => 'TEST_PARTNER_VALUE']],

    'case 6: stats data' => [6, 60606, 'TEST_DESCRIPTION_6', 'TESTREFERENCE6', function (OrderRequest $request) {
        $request->merge(['stats' => [
            'extra1' => 'TEST_CUSTOM_EXTRA1',
            'extra2' => 'TEST_CUSTOM_EXTRA2',
            'extra3' => 'TEST_CUSTOM_EXTRA3',
            'tool' => 'TEST_TOOL',
            'info' => 'TEST_INFO',
            'domainId' => 'TEST_DOMANID',
            'promotorId' => 123456789,
        ]]);
    }],

    'case 7: Google Analytics code' => [7, 70707, 'TEST_DESCRIPTION_7', 'TESTREFERENCE7', function (OrderRequest $request) {
        $request->merge(['customer' => ['gaClientId' => 'TEST_GA_CLIENT_ID']]);
    }],

    'case 8: customer data' => [8, 80808, 'TEST_DESCRIPTION_8', 'TESTREFERENCE8', function (OrderRequest $request) {
        $request->setCustomer((new Customer)->setReference('TEST_CUSTOMER_REFERENCE')->setEmail('test-email@example.com')->setTrust('7'));
    }],

    'case 9: multibyte characters' => [9, 90909, 'TEST_DÉSCRÍPTÍON_9', 'TESTREFERENCE9', function (OrderRequest $request) {
        $request->setCustomer((new Customer)
            ->setFirstName('TEST_CUSTOMER_FÍRST_NÄMÊ')
            ->setLastName('TEST_CUSTOMER_LÄST_NÄMÊ')
            ->setReference('TEST_CUSTOMER_RÊFÊRÊNCÊ')
            ->setCompany((new Company)->setName('TEST_COMPÄNY_NÂMÊ')));

        $order = new Order;
        $order->setDeliveryAddress((new Address)->setStreetName('TEST_DÊLÍVÊRY_STRÊÊT')->setStreetNumberExtension('å, fīrst fløør')->setCity('TEST_DÊLÍVÊRY_CÎTY')->setRegionCode('TEST_DÊLÍVÊRY_RĘGÍON'));
        $order->setInvoiceAddress((new Address)->setStreetName('TEST_ÍNVŒÍCÊ_STRÊÊT')->setStreetNumberExtension('b, sêcõnd flòòr')->setCity('TEST_ÍNVŒÍCÊ_CÍTY')->setRegionCode('TEST_ÍNVŒÍCÊ_RÊGÍÒN'));
        $order->setProducts((new Products)->addProduct(new Product(null, 'TEST_PRØDŪCT_1_DĖSCRĮPTÏŌN', 909.09, 'EUR', 'ARTICLE', 1)));
        $request->setOrder($order);
        // The SDK sends transferData as a list of name/value pairs; Pay. reads it as an object
        $request->merge(['transferData' => ['test' => 'TEST_TRÀNSFÊR_DÂTÀ_ITĘM'], 'stats' => [
            'extra1' => 'TEST_CÙSTÕM_ÊXTRÄ1',
            'extra2' => 'TEST_CÙSTÕM_ÊXTRÄ2',
            'extra3' => 'TEST_CÙSTÕM_ÊXTRÄ3',
            'tool' => 'TEST_TŌŌL',
            'info' => 'TEST_ÍNFŌ',
            'object' => 'TEST_PÂRTNËR_VÆLÙĘ',
            'domainId' => 'TESTDŌMÄÍNÍD',
        ]]);
    }],
]);

function address(string $street, string $number, string $extension, string $zipCode, string $city, string $country, string $region): Address
{
    return (new Address)->setStreetName($street)->setStreetNumber($number)->setStreetNumberExtension($extension)
        ->setZipCode($zipCode)->setCity($city)->setCountryCode($country)->setRegionCode($region);
}

// The sandbox checks TEST_DESCRIPTION_n (as in Pay.'s sample bodies), not the values in its docs tables
it('passes certification test', function (int $case, int $amount, string $description, string $reference, ?Closure $configure, array $config = []) {
    config($config);

    $payment = app(PaynlService::class)->startOnlinePayment($amount, $description, 'https://example.com/return', $reference, SANDBOX_METHOD, $configure);

    expect($payment->orderId)->not->toBeEmpty()
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->paymentUrl)->toStartWith('https://');

    Sandbox::assertCertificationCase($payment, $case);
})->with('certification cases');

it('reads the status of an order back', function () {
    $created = $this->paynl->startOnlinePayment(1234, 'Status check', 'https://example.com/return', 'STATUS-'.time(), SANDBOX_METHOD);

    $payment = $this->paynl->getStatus($created->orderId);

    expect($payment->orderId)->toBe($created->orderId)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(1234);
});

it('cancels a pending order and confirms it through a verified exchange', function () {
    Event::fake([PaymentStatusUpdated::class]);
    $created = $this->paynl->startOnlinePayment(500, 'Cancel check', 'https://example.com/return', 'CANCEL-'.time(), SANDBOX_METHOD);

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

// Pay. does not check the terminal code on Order:Create: the order is created and waits for a
// terminal that never answers, so a POS picks its terminal from listTerminals() and cancels on timeout.
it('leaves a payment to a terminal that is not on the account pending until the POS cancels it', function () {
    $payment = $this->paynl->startTerminalPayment(1, 'TH-0000-0000', 'Unknown terminal', 'UNKNOWN-'.time());

    expect($this->paynl->getStatus($payment->orderId)->status)->toBe(PaymentStatus::Pending)
        ->and($this->paynl->cancel($payment->orderId)->status)->toBe(PaymentStatus::Cancelled);
});

// Pay.'s gateway fallback test: a payment must also go through when the app is switched to another core (PAYNL_CORE)
it('creates and reads an order on a fallback core', function (string $core) {
    config(['paynl.core' => $core]);
    $paynl = app(PaynlService::class);

    $created = $paynl->startOnlinePayment(1234, 'Fallback core', 'https://example.com/return', 'CORE-'.time(), SANDBOX_METHOD);
    $payment = $paynl->getStatus($created->orderId);

    expect($payment->orderId)->toBe($created->orderId)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($paynl->cancel($created->orderId)->status)->toBe(PaymentStatus::Cancelled);
    Sandbox::say("{$core}: order {$created->orderId} created, read and cancelled");
})->with([
    'multicore' => 'https://connect.pay.nl',
    'rerouted multicore (Cloudflare)' => 'https://connect.premium-pay.nl',
    'direct core payments.nl' => 'https://connect.payments.nl',
    'direct core achterelkebetaling.nl' => 'https://connect.achterelkebetaling.nl',
]);
