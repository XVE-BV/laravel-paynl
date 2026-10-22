# xve/laravel-paynl

Laravel integration for Pay.nl, built on the official [`paynl/php-sdk`](https://github.com/paynl/php-sdk) (Order:Create / TGU API). One code base for every XVE project and package that takes Pay. payments: terminal (instore, Bancontact on the kassa) and online.

## Install

Private package: not on Packagist. Add the repository to the app's `composer.json` once:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:XVE-BV/laravel-paynl.git" }
]
```

```bash
composer require xve/laravel-paynl:^1.0
php artisan vendor:publish --tag=paynl-config   # optional
```

`paynl/php-sdk` still requires Guzzle 7. If the app is locked on Guzzle 8, add `-W` so Composer may downgrade it.

Composer needs read access to the repository: an SSH key with access, or a GitHub token (`composer config --global github-oauth.github.com <token>`, or an `auth.json` on servers/CI that is not committed).

Another XVE package builds on it the same way: `"xve/laravel-paynl": "^1.0"` in its own `require`, and the repository entry in the app.

## Updates

Releases are git tags (`v1.0.0`, `v1.1.0`, ...) following semver, listed in `CHANGELOG.md`. Apps update with `composer update xve/laravel-paynl`; a breaking change gets a new major (`v2.0.0`) and is only picked up after the app changes its constraint.

## Configure

```dotenv
PAYNL_TOKEN_CODE=AT-####-####   # token code
PAYNL_API_TOKEN=                # API token next to it
PAYNL_SERVICE_ID=SL-####-####   # sales location
PAYNL_SERVICE_SECRET=           # only when Pay. signs the exchange with the sales location secret
PAYNL_TESTMODE=false
PAYNL_STATS_OBJECT=             # leave empty: every order sends XVE's PartnerID (PaynlService::PARTNER_ID)
PAYNL_STATS_TOOL=               # defaults to APP_NAME
PAYNL_CORE=                     # optional, e.g. https://connect.payments.nl when connect.pay.nl is down
```

In the Pay. dashboard, on the sales location:

- Exchange method **SIGNED_JSON_POST** and a retry scheme (Pay. recommends "6 times within 2 hours").
- Payment method **PIN (1927)** active for terminal payments; the refund contract on the terminal for terminal refunds.

## Use

Amounts are **integer cents** (12,10 EUR = `1210`), as Pay. counts them.

```php
use Xve\LaravelPaynl\Facades\Paynl;

Paynl::listTerminals();                                           // Terminal[] (code, name, status, connectionStatus, paymentTypes, usable, supportsRefund)
$payment = Paynl::startTerminalPayment(1210, 'TH-1234-4321', 'Kassa', 'ref-1');
$payment = Paynl::startOnlinePayment(2500, 'Webshop', route('shop.done'), 'ORD-1');
redirect($payment->paymentUrl);

Paynl::getStatus($payment->orderId);                              // Payment with a normalised PaymentStatus
Paynl::verifyPaid($payment->orderId, 1210);                       // Payment, or PaymentNotVerifiedException (reason: not_paid / amount_mismatch)
Paynl::cancel($payment->orderId);                                 // also stops a payment waiting on the terminal

Paynl::refund($orderId, 500, 'Retour');                           // online orders; null amount = full refund
Paynl::startTerminalRefund(500, 'TH-1234-4321', 'Retour');        // back to the card on the terminal
Paynl::approve($orderId); Paynl::decline($orderId);               // orders in Verify
Paynl::capture($orderId, 1210); Paynl::void($orderId);            // orders in Authorized
```

In a package, type-hint `Xve\LaravelPaynl\Contracts\PaymentGateway` instead of `PaynlService`, so `Paynl::fake()` can stand in for it.

Order data the methods do not cover (customer, order lines for Klarna/in3/Riverty, expire time, language) goes through `configure`, which gets an `Xve\LaravelPaynl\Support\OrderRequest` (the SDK's `OrderCreateRequest`):

```php
Paynl::startOnlinePayment(2500, 'Webshop', $returnUrl, 'ORD-1', configure: function (OrderRequest $request) {
    $request->setCustomer($customer);   // PayNL\Sdk\Model\Customer
    $request->setOrder($order);         // PayNL\Sdk\Model\Order, with Xve\LaravelPaynl\Support\Product lines
    $request->setExpire(now()->addHour()->toAtomString());

    // Fields the SDK models do not send, merged into the Order:Create body as is
    $request->merge([
        'customer' => ['gaClientId' => $gaClientId, 'company' => ['country' => 'NL']],
        'stats' => ['extra2' => $shop, 'promotorId' => 123456789],
        'transferData' => ['shop' => $shop],
    ]);
});
```

Build order lines with `Xve\LaravelPaynl\Support\Product` instead of the SDK's `Product` (same constructor, unit price in euros): the SDK turns "Crème brûlée" into ASCII, ours sends it as is. The SDK sends the company country as `countryCode` and `transferData` as name/value pairs, while Pay. reads `company.country` and a `transferData` object: send those through `merge()`. The sandbox suite proves each of these at Pay. (certification cases 3, 6, 7 and 9).

Call `verifyPaid()` before booking anything: it asks Pay. itself instead of trusting a browser or return url that says "paid".

Once the terminal is done, `Payment::$receipt` holds the card receipt: `lines` (as the terminal prints it), `text()`, `ticketData` (the same as separate fields), `authorizationCode` and `signatureRequired` (the customer signs the merchant copy). Pay. requires the kassa to offer it after a successful payment: print it, show it or mail it.

`PaymentStatus` is one of `pending`, `verify` (held by Pay.'s fraud check: tell the customer the order is received, do not ship until `approve()`), `authorized`, `paid`, `cancelled` (also expired and failed), `partially_refunded`, `refunded`, `chargeback`. `Payment::$statusCode` keeps the raw Pay. code. Every order carries the `stats` block Pay. needs for certification: `object` is XVE's PartnerID (`PaynlService::PARTNER_ID`, the same in every project), `tool` the app name.

## Exchange (webhook)

Every order is created with the exchange url (`/paynl/exchange`). A signed call is checked against the API token (or `PAYNL_SERVICE_SECRET` when signed with the sales location); an unsigned one is only used to look the status up at Pay. Then `Xve\LaravelPaynl\Events\PaymentStatusUpdated` fires with the `payment`.

Pay. can send the same call more than once and not always in order, and every package in the app gets every event. So listeners look up their own order, ignore what is not theirs, and never move an order back:

```php
Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) {
    $order = Order::firstWhere('paynl_order_id', $event->payment->orderId);
    if ($order && $event->payment->isPaid() && ! $order->isPaid()) {
        $order->markPaid();
    }
});
```

A listener that throws makes the exchange answer `FALSE`, so Pay. retries. The route has no middleware (no auth, CSRF or rate limit), and works under Octane: the signature is checked on the Laravel request.

## Screens

The package ships no routes for a kassa or checkout screen: each app exposes its own endpoints (with its own url, auth and response shape) and calls the package from there. `Payment::$statusUrl` is Pay.'s public status link for a POS that polls Pay. directly.

## Terminal payments in a kassa

`Xve\LaravelPaynl\Services\TerminalPayments` does everything Pay.'s POS certification asks of a kassa, so its endpoints stay thin and its screen only shows what it gets back:

```php
public function start(Request $request, TerminalPayments $terminal)
{
    // The screen sends a new reference per attempt: the same one again follows that order instead of charging twice
    return $terminal->start(1210, $request->terminal, 'Kassa', $request->reference, context: ['sale' => $request->sale]);
}

public function status(string $orderId, TerminalPayments $terminal) { return $terminal->status($orderId); }
public function cancel(string $orderId, TerminalPayments $terminal) { return $terminal->cancel($orderId); }
```

- `start()` refuses a terminal that is unknown, offline or inactive, and says when Pay. refuses because the terminal is busy. The running payment is only kept in the cache (`paynl.terminal.remember`, 24 hours: long enough for Pay.'s exchange retries), with the `context` the app needs to book it and the user who started it. No tables: the app's own records stay the truth about what is booked.
- `start()`, `status()` and `cancel()` return a `TerminalPaymentStatus`: the payment plus `final`, `message` (one per outcome: paid, cancelled from the kassa, timeout, expired, card declined, stopped on the terminal with Pay.'s reason, refunded; Dutch and English) and `pollAfterMs`. The screen shows `message` and asks again after `pollAfterMs`; `null` means stop. Pay.'s rhythm (3 seconds, then at most once a second) and the timeout (`paynl.terminal.timeout`, after which the payment is stopped) live in the package.
- `book($orderId, $amountInCents, fn (Payment $payment, TerminalPayment $terminalPayment) => ..., $booked)` runs the app's booking once Pay. confirms the amount, in one transaction and exactly once per order; a second call gets what the first one returned. `$booked` checks the app's own records (e.g. a payment with this `transaction_id`) and returns the answer for an order booked already, or null, so nothing is booked twice when the cache was cleared.
- When only the exchange learns the payment was paid (the kassa closed), `TerminalPaymentPaid` fires as the cashier who started it. The app listens and books from `$event->terminalPayment->context` through `book()`.
- What cannot be started or booked throws `TerminalPaymentException`, which Laravel answers as JSON with the message for the cashier.
- `Receipt::printLines()` gives the card receipt to print, with a merchant copy to sign when `signatureRequired`.

## Testing an app or package that uses it

```php
$paynl = Paynl::fake();

$this->post('/checkout', [...]);

$paynl->assertPaymentStarted(fn (array $started) => $started['amount'] === 2500 && $started['reference'] === 'ORD-1');
$paynl->markAs($paynl->lastPayment()->orderId, PaymentStatus::Paid);   // as the exchange would
PaymentStatusUpdated::dispatch($paynl->lastPayment());                 // run your listeners
```

Also `assertNothingStarted()`, `assertRefunded()`, `started()` and `withTerminals(...)`; `markAs()` takes a `failureReason` and a `Receipt` for the kassa screens. The fake replaces the gateway; the exchange route keeps verifying for real.

## Test

```bash
composer test            # unit/feature tests, no network
composer test:sandbox    # real Pay. API with test credentials, sandbox orders only
composer test:terminal   # the Pay. POS certification scenarios on a real test terminal
```

The last two read their credentials from the environment or a git-ignored `.env.sandbox` (copy `.env.sandbox.example`) and are skipped without them. Use the test account / test sales location only.

`test:sandbox` runs unattended: authentication, terminal list, Pay.'s data integrity cases 1 to 9 (each test reads Pay.'s own field-by-field check from the sandbox payment page and fails on any mismatch), the gateway fallback on every Pay. core, status, cancel, a verified exchange and a forged one.

`test:terminal` needs someone at the terminal (`PAYNL_SANDBOX_TERMINAL`) and covers the scenarios Pay. certifies: successful payment (with receipt), stop on the terminal, timeout, terminal busy (from the Collect desk, and from a second POS payment), no connection, refund on the terminal (skipped without the refund contract), and a cancel from the POS. Run them one at a time and follow the console:

```bash
composer test:terminal -- --filter="scenario 2"
```

Each scenario prints the status, `failureReason` and receipt; that is what the kassa screen must show the cashier. Every result is also written to `build/paynl-certification.md`, the evidence Pay. asks for. [CERTIFICATION.md](CERTIFICATION.md) is the full run book.

## Going live

- Sales location on LIVE at Pay., `PAYNL_TESTMODE=false`, live credentials.
- POS certification with Pay. done (see [CERTIFICATION.md](CERTIFICATION.md)), and a release of this package with the PartnerID Pay. handed out in `PaynlService::PARTNER_ID` (now still `TEST_PARTNER_VALUE`).
- `APP_URL` is the public https url, so the exchange url in every order reaches the app.
