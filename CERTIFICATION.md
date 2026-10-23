# Pay. certification run book

Pay. certifies an integration (the kassa or webshop built on this package), not the package itself. XVE certifies once as a partner, with one app on its own Pay. test account. Every order sends `PAYNL_STATS_OBJECT` as `stats.object`: `TEST_PARTNER_VALUE` (what Pay. expects in the tests) until Pay. hands out the real PartnerID. Pay.'s POS certification page asks for the POS software name and version instead (`"YOUR_POS_SOFTWARE_NAME|1.0"`): ask the implementation manager which value the POS run should send, `PAYNL_STATS_OBJECT` takes either.

Sources: [certification](https://developer.pay.nl/docs/certification), [test your integration](https://developer.pay.nl/docs/testing-your-integration), [Cloud Connect validation checklist](https://support.pay.nl/en/knowledge/pay-pos-cloudconnect-validation-checklist), [partner registration](https://developer.pay.nl/docs/registration).

## 0. Before you start

- Partner registration in My Pay with action code `partner`, and a partner manager / implementation manager at Pay. Contact: `partner_integration@pay.nl`, the [*Pay.integrators* Slack channel](https://paynl.slack.com/archives/C04LV44909M) or an [eMeeting with Pay.integrators](https://outlook.office365.com/owa/calendar/PayIntegrators@pay.nl/bookings/).
- Test only on XVE's own Pay. account. Pay. does not allow testing as a third party on a merchant's account.
- On the test sales location: PIN (1927) active, exchange **SIGNED_JSON_POST** with a retry scheme, and the refund contract on the terminal if refunds are in scope (the terminal then lists `REFUND` in `paymentTypes`).
- `.env.sandbox` filled in (copy `.env.sandbox.example`). For the terminal scenarios, `PAYNL_SANDBOX_URL` points to a public tunnel (Expose/ngrok) so Pay. can reach the exchange.

## 1. Unattended: `composer test` and `composer test:sandbox`

Both must be green. `test:sandbox` proves at Pay. itself:

| What Pay. checks | Test |
|---|---|
| Data integrity cases 1-9 (amounts 10101 ... 90909) | `passes certification test`: reads Pay.'s per-field check from the sandbox page |
| Gateway fallback: connect.pay.nl, connect.premium-pay.nl, connect.payments.nl, connect.achterelkebetaling.nl | `creates and reads an order on a fallback core` |
| Exchange: signed calls trusted, forged calls refused | `cancels a pending order ...`, `rejects a forged exchange ...` |

Pay.'s sandbox does not check every case against its own docs: it wants `TEST_DESCRIPTION_n` as the description, `TESTREFERENCE2` for case 3 and `TESTREFERENCE` for case 4. The tests send exactly what the sandbox checks.

## 2. At the terminal: `composer test:terminal`

Run one scenario at a time (`composer test:terminal -- --filter="scenario 1"`) and keep the kassa app open next to it. Pay. checks that **the kassa shows the right message** for each one.

| # | Scenario | Do | Expected | The kassa shows |
|---|---|---|---|---|
| 1 | Successful payment | Pay with a card (also contactless and a phone wallet) | `paid` + receipt | Paid, and offers the receipt |
| 2 | User cancellation | Press the red STOP on the terminal | `cancelled` + `failureReason` | Stopped by the customer, can retry |
| 3 | Timeout | Do not touch the terminal | `cancelled` | Expired, can retry |
| 4 | Terminal busy | Start a payment from the Pay. Admin Panel (Collect desk) when the test asks | refused or `cancelled` | Terminal is busy |
| 5 | No connection | Unplug the network cable / wifi when the test asks | refused or `cancelled` | Terminal not reachable |
| 6 | Refund | Present the card for the refund | final, not `cancelled` | Refunded, with receipt (skipped without the refund contract) |
| - | Cancel from the POS | Nothing | `cancelled` | Cancelled |

Every result (order, reference, status, reason, receipt) is written to `build/paynl-certification.md`.

## 3. What Pay. checks in the app

The package provides the data; the app has to use it:

- One message per scenario above, from `Payment::$status` and `Payment::$failureReason`.
- After a successful payment the receipt is offered (print, show or mail) from `Payment::$receipt`; when `signatureRequired`, the customer signs the merchant copy.
- The kassa polls the status 3 seconds after starting, then at most once a second, and stops at a final status (`PaymentStatus::isFinal()`). The exchange is the fallback.
- Retrying after a failure starts a new payment; a double click never starts two.
- With more than one terminal, the cashier picks one from `listTerminals()` (only `usable` ones), and the payment goes to that terminal.
- Online orders in `verify`: lower the stock and tell the customer the order is received, not paid. On `decline()`: mail the customer and put the stock back. On `approve()`: ready to ship.
- `stats.object` is the same on every order: `PAYNL_STATS_OBJECT` is set to the PartnerID.
- The cashiers know how to switch a terminal to another core in an outage, and the app has `PAYNL_CORE` for the gateway.

## 4. Send Pay. the evidence

- `build/paynl-certification.md`: references of a successful, failed, cancelled and refunded payment, the terminal, `stats.object` and the receipts.
- Screenshots or a screen recording of the kassa for each scenario.

## 5. After the certification

- Put the PartnerID in `PAYNL_STATS_OBJECT` of every project.
- Ask Pay. whether another app built on the package (a second kassa, a webshop) needs its own certification or falls under the same integration.
