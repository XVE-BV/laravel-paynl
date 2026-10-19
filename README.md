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
PAYNL_STATS_OBJECT=             # "POSNAME|version", or the partner code from Pay. (TEST_PARTNER_VALUE during certification)
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

Paynl::listTerminals();                                           // Terminal[] (code, name, status, connectionStatus, usable)
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

Order data the methods do not cover (customer, order lines for Klarna/in3/Riverty, expire time, language) goes through `configure`:

```php
Paynl::startOnlinePayment(2500, 'Webshop', $returnUrl, 'ORD-1', configure: function (OrderCreateRequest $request) {
    $request->setCustomer($customer);   // PayNL\Sdk\Model\Customer
    $request->setOrder($order);         // PayNL\Sdk\Model\Order, with the products
    $request->setExpire(now()->addHour()->toAtomString());
});
```

Call `verifyPaid()` before booking anything: it asks Pay. itself instead of trusting a browser or return url that says "paid".

`PaymentStatus` is one of `pending`, `verify` (held by Pay.'s fraud check: tell the customer the order is received, do not ship until `approve()`), `authorized`, `paid`, `cancelled` (also expired and failed), `partially_refunded`, `refunded`, `chargeback`. `Payment::$statusCode` keeps the raw Pay. code. Every order carries the `stats` block (`object`, `tool`, `extra1`) Pay. needs for certification.

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

The package ships no routes for a kassa or checkout screen: each app exposes its own endpoints (with its own url, auth and response shape) and calls the gateway from there. A kassa that polls should wait about 3 seconds, then ask at most once a second; `Payment::$statusUrl` is Pay.'s public status link for a POS that polls Pay. directly.

## Testing an app or package that uses it

```php
$paynl = Paynl::fake();

$this->post('/checkout', [...]);

$paynl->assertPaymentStarted(fn (array $started) => $started['amount'] === 2500 && $started['reference'] === 'ORD-1');
$paynl->markAs($paynl->lastPayment()->orderId, PaymentStatus::Paid);   // as the exchange would
PaymentStatusUpdated::dispatch($paynl->lastPayment());                 // run your listeners
```

Also `assertNothingStarted()`, `assertRefunded()`, `started()` and `withTerminals(...)`. The fake replaces the gateway; the exchange route keeps verifying for real.

## Test

```bash
composer test            # unit/feature tests, no network
composer test:sandbox    # real Pay. API with test credentials, sandbox orders only
composer test:terminal   # the Pay. POS certification scenarios on a real test terminal
```

The last two read their credentials from the environment or a git-ignored `.env.sandbox` (copy `.env.sandbox.example`) and are skipped without them. Use the test account / test sales location only.

`test:sandbox` runs unattended: authentication, terminal list, certification cases 1 and 5 (amounts 10101 and 50505; open the printed payment url to see Pay.'s check), status, cancel, a verified exchange and a forged one.

`test:terminal` needs someone at the terminal (`PAYNL_SANDBOX_TERMINAL`) and covers the scenarios Pay. certifies: successful payment, stop on the terminal, timeout, terminal busy, no connection, refund on the terminal, and a cancel from the POS. Run them one at a time and follow the console:

```bash
composer test:terminal -- --filter="scenario 2"
```

Each scenario prints the status and `failureReason`; that is what the kassa screen must show the cashier.

## Going live

- Sales location on LIVE at Pay., `PAYNL_TESTMODE=false`, live credentials.
- POS certification with Pay. done (scenarios 1 to 5, and 6 when terminal refunds are used), with `PAYNL_STATS_OBJECT` set to what Pay. registered.
- `APP_URL` is the public https url, so the exchange url in every order reaches the app.
