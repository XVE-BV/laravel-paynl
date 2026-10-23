# Changelog

All notable changes, per release tag. Follows [semver](https://semver.org): patch = fix, minor = new feature (backwards compatible), major = breaking change.

## 1.0.0

- Terminal payments (list terminals, start, status, cancel), online payments, verified exchange with `PaymentStatusUpdated`, Pay. stats fields.
- Amounts are integer cents everywhere (`Payment::$amount`, `startOnlinePayment(2500, ...)`).
- `Paynl` facade, `PaymentGateway` contract and `Paynl::fake()` with assertions, for the apps and packages built on it.
- The exchange checks the HMAC signature itself on the Laravel request (Octane-safe) and asks Pay. for the status of any unsigned call; answers JSON for JSON exchanges.
- Refunds (`refund()`, `startTerminalRefund()`), `approve()` / `decline()` for orders in Verify, `capture()` / `void()` for authorized orders.
- `PaymentStatus::Verify` and `PaymentStatus::PartiallyRefunded`; `Payment::$statusCode`, `$currency` and `$statusUrl`.
- `Payment::$failureReason` with the reason Pay. gives for a stopped or refused terminal payment.
- `verifyPaid()` confirms with Pay. that an order is paid for the expected amount, or throws `PaymentNotVerifiedException`.
- Calls to Pay. bypass the SDK status cache (which kept the first status for the whole process) and time out after 10 seconds.
- `Payment::$receipt` with the card receipt of a terminal payment or refund (`lines`, `text()`, `ticketData`, `authorizationCode`, `signatureRequired`); `Receipt::printLines()` adds a merchant copy to sign when the terminal asks for a signature.
- `configure` callbacks get an `OrderRequest` with `merge()` for the fields the SDK does not send (`customer.gaClientId`, `customer.company.country`, `stats.promotorId`, a `transferData` object).
- `Support\Product`: order lines in UTF-8, without the SDK's transliteration to ASCII.
- `Terminal::$paymentTypes` and `supportsRefund()`.
- `TerminalPayments` for a kassa: start a terminal payment once per reference (refuses unknown, offline and inactive terminals, says when the terminal is busy), follow it with a `TerminalPaymentStatus` (`final`, a message per outcome in Dutch and English, `pollAfterMs`), stop it after `paynl.terminal.timeout`, cancel from the kassa, and `book()` it exactly once after Pay. confirms the amount.
- `TerminalPaymentPaid`: fired as the cashier who started it when the exchange reports a terminal payment as paid that is not booked yet. Running terminal payments are only kept in the cache (`paynl.terminal.remember`), no tables.
- `TerminalPaymentException`, rendered as JSON with the message for the cashier.
- `stats.object` comes from `PAYNL_STATS_OBJECT`: the PartnerID Pay. hands out (`TEST_PARTNER_VALUE` during the certification).
- Sandbox and terminal certification test suites (`composer test:sandbox`, `composer test:terminal`): Pay.'s data integrity cases 1 to 9 with Pay.'s own verdict for each, the gateway fallback on every Pay. core, and the POS scenarios with the evidence for Pay. in `build/paynl-certification.md`. Run book in `CERTIFICATION.md`.
- Public repository under the MIT license, installed through a `repositories` entry with `https://github.com/XVE-BV/laravel-paynl`: no GitHub token needed.
