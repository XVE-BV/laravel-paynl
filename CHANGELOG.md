# Changelog

All notable changes, per release tag. Follows [semver](https://semver.org): patch = fix, minor = new feature (backwards compatible), major = breaking change.

## Unreleased

- Terminal payments (list terminals, start, status, cancel), online payments, verified exchange with `PaymentStatusUpdated`, Pay. stats fields.
- Amounts are integer cents everywhere (`Payment::$amount`, `startOnlinePayment(2500, ...)`).
- `Paynl` facade, `PaymentGateway` contract and `Paynl::fake()` with assertions, for the apps and packages built on it.
- The exchange checks the HMAC signature itself on the Laravel request (Octane-safe) and asks Pay. for the status of any unsigned call; answers JSON for JSON exchanges.
- Refunds (`refund()`, `startTerminalRefund()`), `approve()` / `decline()` for orders in Verify, `capture()` / `void()` for authorized orders.
- `PaymentStatus::Verify` and `PaymentStatus::PartiallyRefunded`; `Payment::$statusCode`, `$currency` and `$statusUrl`.
- `Payment::$failureReason` with the reason Pay. gives for a stopped or refused terminal payment.
- `verifyPaid()` confirms with Pay. that an order is paid for the expected amount, or throws `PaymentNotVerifiedException`.
- Calls to Pay. bypass the SDK status cache (which kept the first status for the whole process) and time out after 10 seconds.
- Sandbox and terminal certification test suites (`composer test:sandbox`, `composer test:terminal`).
