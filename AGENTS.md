# Oblodai PHP SDK — guide for coding agents

Package `oblodai/sdk` (1.3). Everything below is verified against the gateway's contract snapshot
shipped in `contract/contract.json`.

## Non-negotiables

- Amounts are decimal **strings**: `'amount' => '25'`, never `25` or `25.0`. Do not cast a money
  field to float; use `Oblodai\Helper\Money::add()` / `compare()`.
- Every method's **last** argument is
  `new RequestOptions(idempotencyKey: …, timeoutMs: …, deadlineMs: …, preferPayoutKey: …)`.
- Two key kinds. The **payout key** is required for: `payouts->*`, `refunds->*`, `payoutLinks->*`,
  `transfers->*`, `splits->*`, `wallets->refundBlockedDeposit()`, `settings->*AutoWithdraw()`,
  `settings->*ApiAllowlist()`, `webhooks->rotateSecret()`, `webhooks->test('payout', …)`,
  `sandbox->faucet()`, `sandbox->reset()`. Configure it with `payoutPublicId:`/`payoutSecret:` (or
  `OBLODAI_PAYOUT_*`); a wrong kind is a 403 `merchant.wrong_key_kind`.
- List methods return `Oblodai\Core\Page`: `->items()`/`->paginate()` is ONE page, `foreach` walks
  every page, `->all($max)` collects. Nothing is requested until it is consumed.
- Idempotency keys are generated automatically on create routes and reused across retries. Passing
  `idempotencyKey` to a route the core does not deduplicate throws `sdk.idempotency_unsupported`.
- Request bodies are `array<string, mixed>` or a generated DTO from `Oblodai\Contract\Request\*`
  (that is where the field documentation lives). Wire field names are snake_case, always.

## Naming

| intent            | call                                                                                                          |
| ----------------- | ------------------------------------------------------------------------------------------------------------- |
| fetch one         | `->info($uuid)` or `->info(['order_id' => …])` (alias `->get()`)                                               |
| fetch many        | `->history($params)` on payments/payouts (alias `->list()`), `->list($params)` elsewhere                       |
| create            | `->create($params)`; webhooks: `->register($url)`                                                              |
| many, synchronous | `payouts->mass()`, `payoutLinks->batch()` — ≤100, `list<BatchElement>` with per-element outcomes              |
| many, async       | `payments->batch()`, `payouts->batch()`, `refunds->batch()`, `transfers->batch()` — ≤5000, poll `batches->info()` |
| documents         | `documents->*Report()` / `statement()` / `feeSchedule()` / `balanceCertificate()` → `FileResult`               |
| provisioning      | `merchants->create()`, `merchants->createSandbox($id)` — no HMAC; `adminToken:` on self-hosted gateways        |
| payer-facing      | `payments->publicView/select/publicQr`, `paymentLinks->publicView/checkout`, `payoutLinks->claimPreview/claim` |

## Errors

`catch (OblodaiException $err)` → `$err->errorCode` (`family.reason`), `httpStatus`, `retryable`
(authoritative — the SDK already retried what it should), `retryAfter`, `requestId` (quote to
support), `field` (400s), `synthetic` (the answer came from a proxy, not the API).
Subclasses: `ValidationException` 400, `AuthenticationException` 401, `PermissionException` 403,
`NotFoundException` 404, `ConflictException`/`IdempotencyConflictException` 409,
`RateLimitException` 429, `UnavailableException` 503, `InternalException` other 5xx,
`TransportException` (no response), `ConfigException` (before sending), `ContractException`
(unreadable envelope), `SignatureException` (webhooks). `json_encode($err)` keeps the message and
drops the raw body.

Codes worth handling: `payout.insufficient_funds` (retryable), `payout.funds_maturing` (retryable),
`idempotency.key_reused`, `invoice.not_payable`, `payment.not_found`, `merchant.wrong_key_kind`,
`merchant.bad_signature`, `request.rate_limited`. Full list: `Oblodai\Contract\Enums::ERROR_CODES`.

## Statuses

- Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
  `Status::isPaymentPaid()` = paid/paid_over. `wrong_amount` needs
  `refunds->resolve(['uuid' => …, 'action' => …])`.
- Payout: `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.
- Webhook event types: `invoice.<status>`, `payout.<status>`, `wallet.paid`; the body's `type` is
  `payment|payout|wallet` and decodes into `PaymentEvent`, `PayoutEvent` or `WalletEvent`.
- Closed vocabularies decode into PHP enums and a value outside this snapshot raises
  `ContractException` ("upgrade the SDK") instead of being guessed at. Open ones (`network`, `kind`,
  `fee_type`, `source`, `event_type`) stay strings. Every model keeps the raw wire body in `->raw`.

## Webhooks

```php
use Oblodai\Webhook\Verifier;

$delivery = Verifier::verify(file_get_contents('php://input'), getallheaders(), $secret);
```

Verify over the **raw** bytes. Deduplicate on `$delivery->id` (`X-Webhook-Id`); drop out-of-order
events with `Verifier::isStale($event, $lastSequence)`. During a rotation pass `previousSecret:`
for ≥26 h.

## Machine-readable surface

`Oblodai\Contract\Routes::SPECS` (107 routes: path, auth, idempotent, safe, bare, list),
`Oblodai\Contract\Request\*` (typed bodies per route), `Oblodai\Contract\Enums` (statuses, networks,
event types, 468 error codes), `Oblodai\Contract\Enum\*` (the same as PHP enums), and `contract/`
itself (schemas, golden response bodies per route, error samples, signed webhook samples).
