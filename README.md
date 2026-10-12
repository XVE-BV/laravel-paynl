# xve/laravel-paynl

Laravel integration for Pay.nl, built on the official [`paynl/php-sdk`](https://github.com/paynl/php-sdk) (Order:Create / TGU API). One code base for every XVE project that takes Pay. payments: terminal (instore, Bancontact on the kassa) and online.

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

## Updates

Releases are git tags (`v1.0.0`, `v1.1.0`, ...) following semver, listed in `CHANGELOG.md`. Apps update with `composer update xve/laravel-paynl`; a breaking change gets a new major (`v2.0.0`) and is only picked up after the app changes its constraint.

```dotenv
PAYNL_TOKEN_CODE=AT-####-####   # token code
PAYNL_API_TOKEN=                # API token next to it
PAYNL_SERVICE_ID=SL-####-####   # sales location
PAYNL_TESTMODE=false
PAYNL_STATS_OBJECT=             # partner/integration code from Pay. (TEST_PARTNER_VALUE during certification)
PAYNL_STATS_TOOL=               # defaults to APP_NAME
```

## Use

```php
use Xve\LaravelPaynl\Services\PaynlService;

$paynl = app(PaynlService::class);

$paynl->listTerminals();                                         // Terminal[] (code, name, status, connectionStatus, usable)
$payment = $paynl->startTerminalPayment(12.10, 'TH-1234-4321', 'Kassa', 'ref-1');
$payment = $paynl->startOnlinePayment(25.00, 'Webshop', route('shop.done'), 'ORD-1');
redirect($payment->paymentUrl);

$paynl->getStatus($payment->orderId);                            // Payment with a normalised PaymentStatus
$paynl->verifyPaid($payment->orderId, 12.10);                     // Payment, or PaymentNotVerifiedException (reason: not_paid / amount_mismatch)
$paynl->cancel($payment->orderId);
```

Call `verifyPaid()` before booking anything: it asks Pay. itself instead of trusting a browser that says "paid".

`PaymentStatus` is one of `pending`, `authorized`, `paid`, `cancelled`, `refunded`, `chargeback`. Every order carries the `stats` block (`object`, `tool`, `extra1`) Pay. needs for certification.

## Exchange (webhook)

Every order is created with the package's exchange url (`/paynl/exchange`). The call is verified (HMAC signature, or a status call to Pay.) and fires `Xve\LaravelPaynl\Events\PaymentStatusUpdated`. Pay. can send the same call more than once, so make listeners idempotent:

```php
Event::listen(PaymentStatusUpdated::class, function (PaymentStatusUpdated $event) {
    if ($event->payment->isPaid()) {
        // book it once, look it up by $event->payment->orderId
    }
});
```

A listener that throws makes the exchange answer `FALSE`, so Pay. retries.

## Screens

The package ships no routes for a kassa or checkout screen: each app exposes its own endpoints (with its own url, auth and response shape) and calls `PaynlService` from there.

## Test

```bash
composer test            # unit/feature tests, no network
composer test:sandbox    # real Pay. API with test credentials, sandbox orders only
composer test:terminal   # the Pay. POS certification scenarios on a real test terminal
```

The last two read their credentials from the environment or a git-ignored `.env.sandbox` (copy `.env.sandbox.example`) and are skipped without them. Use the test account / test sales location only.

`test:sandbox` runs unattended: authentication, terminal list, certification cases 1 and 5 (amounts 101.01 and 505.05; open the printed payment url to see Pay.'s check), status, cancel, a verified exchange and a forged one.

`test:terminal` needs someone at the terminal (`PAYNL_SANDBOX_TERMINAL`) and covers the scenarios Pay. certifies: successful payment, stop on the terminal, timeout, terminal busy, no connection, and a cancel from the POS. Run them one at a time and follow the console:

```bash
composer test:terminal -- --filter="scenario 2"
```

Each scenario prints the status and `failureReason`; that is what the kassa screen must show the cashier.
