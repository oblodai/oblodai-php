# Oblodai PHP SDK — guide for coding agents

Package `oblodai/sdk` (2.0), PHP ≥ 8.2. The resources, methods and models are generated from the
gateway's OpenAPI contract (`src/Generated`, never edited by hand); `names.lock` lists every public
method as `resource.method` in snake_case (the PHP names are the same in camelCase).

## Non-negotiables

- Amounts are decimal **strings**: `'amount' => '25'`, never `25` or `25.0` — a float in a body is
  `sdk.float_amount` before anything is sent. Use `Oblodai\Helper\Money` to add or compare.
- A body is an array (its shape is in the method's docblock) or the generated request model
  (`Oblodai\Generated\Model\*Request`). Path parameters come first, query parameters are named
  arguments, and every method's **last** argument is
  `new RequestOptions(idempotencyKey: …, timeout: …, maxRetries: …, extraHeaders: […], requestId: …)`
  — `timeout` in seconds.
- **One API key.** `publicId:`/`secret:` (or `OBLODAI_PUBLIC_ID`/`OBLODAI_SECRET`) sign every route.
  `adminToken:` is a self-hosted gateway's provisioning token, sent only on `sandbox->onboardStore()`.
- Idempotency keys are generated automatically on the routes the gateway deduplicates and reused
  across retries. Passing `idempotencyKey` to any other route throws `sdk.idempotency_unsupported`.
- Paged list methods return `Oblodai\Core\Page`: `items()`/`paginate()`/`first()` is ONE page,
  `foreach` walks every item, `byPage()` every page, `all($max)` collects. Nothing is requested until
  it is consumed.
- Batches and document exports: `$resource->asJob(fn ($r) => $r->createPayout(…))->wait()`.

## Names

`client.<resource>.<method>` from the contract's `operationId`: `payments->create()`,
`payments->getInfo(['uuid' => …])`, `payments->listHistory([...])`, `payouts->create()`,
`batches->createPayout()`, `checkout->get($uuid)` (payer-facing, no key), `documents->getStatement(
from: …, to: …)` → `FileResult`. When unsure, read `names.lock` or the method's docblock (the
gateway's own description, route and error codes).

## Errors

`catch (OblodaiException $err)` → `$err->errorCode` (`family.reason`), `detail`, `httpStatus`,
`retryable` (authoritative — the SDK already retried what it should), `retryAfter`, `requestId`
(quote to support), `field` (400s), `synthetic` (the answer came from a proxy). `getMessage()` is
`[code] text (request_id=…)`. Subclasses: `ValidationException` 400, `AuthenticationException` 401,
`PermissionException` 403, `NotFoundException` 404, `ConflictException`/`IdempotencyConflictException`
409, `RateLimitException` 429, `UnavailableException` 503, `InternalException` other 5xx,
`TransportException` (no response), `ConfigException` (before sending), `ContractException`
(unreadable answer), `SignatureException` (webhooks). The full code catalogue is
`Oblodai\Generated\Enum\ErrorCode`.

## Statuses

A status is the generated enum's case, or a plain string for a value newer than this SDK — it never
throws. `Status::value($x->status)` is the wire string; `Status::isPaymentPaid()` (paid/paid_over),
`isPaymentFinal()`, `isPayoutFinal()`, `isPayoutSucceeded()`. `wrong_amount` needs
`payments->resolve(['uuid' => …, 'action' => …])`. Fields newer than the SDK are in `->extra`.

## Webhooks

```php
use Oblodai\Webhook\Verifier;

$delivery = Verifier::verify(file_get_contents('php://input'), getallheaders(), $secret);
$event = Verifier::model($delivery->event); // PaymentWebhook|PayoutWebhook|WalletWebhook|ConversionWebhook|null
```

Verify over the **raw** bytes. `SignatureException` → answer 401; `WebhookPayloadException`
(`webhook.bad_payload`) → the MAC verified but the body is unreadable, answer 2xx and alert;
`ConfigException` → your receiver is misconfigured. `$delivery->isTest` marks a rehearsal (never
money). Deduplicate on `$delivery->eventId`; drop out-of-order events with
`Verifier::isStale($delivery->event, $lastSequence)`. During a rotation pass `previousSecret:` for
≥26 h.

## Machine-readable surface

`Oblodai\Generated\Routes` (every operation: method, path, auth, idempotent, retry-safe, file,
paged), `Oblodai\Generated\Model\*` and `Oblodai\Generated\Enum\*`, `Oblodai\Generated\Facts`
(which operations are jobs and how they end, webhook kinds and their models, non-money numbers),
`Oblodai\Generated\Signing` (the signing protocol: header names, canonical parts, skew, limits),
`names.lock`.
