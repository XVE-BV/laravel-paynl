# Changelog

All notable changes, per release tag. Follows [semver](https://semver.org): patch = fix, minor = new feature (backwards compatible), major = breaking change.

## Unreleased

- Terminal payments (list terminals, start, status, cancel), online payments, verified exchange with `PaymentStatusUpdated`, Pay. stats fields.
- `Payment::$failureReason` with the reason Pay. gives for a stopped or refused terminal payment.
- `PaynlService::verifyPaid()` confirms with Pay. that an order is paid for the expected amount, or throws `PaymentNotVerifiedException`.
- Sandbox and terminal certification test suites (`composer test:sandbox`, `composer test:terminal`).
