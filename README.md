<div align="center">

<a href="https://oblodai.com">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/oblodai/.github/main/brand/logo-white.svg">
    <img src="https://raw.githubusercontent.com/oblodai/.github/main/brand/logo-black.svg" alt="oblodai" height="52">
  </picture>
</a>

<h3>Official PHP SDK for the <a href="https://oblodai.com">oblodai</a> payment gateway</h3>

Payments, payouts, payment links, splits, static wallets, webhooks — one API key.

<a href="https://packagist.org/packages/oblodai/sdk"><img src="https://img.shields.io/packagist/v/oblodai/sdk?style=flat-square&label=Packagist" alt="Packagist"></a>
<a href="https://github.com/oblodai/oblodai-php/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/oblodai/oblodai-php/ci.yml?branch=main&style=flat-square&label=CI" alt="CI"></a>
<a href="https://packagist.org/packages/oblodai/sdk"><img src="https://img.shields.io/packagist/php-v/oblodai/sdk?style=flat-square" alt="PHP version"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-000000?style=flat-square" alt="License: MIT"></a>

[Documentation](https://docs.oblodai.com) · [Dashboard](https://my.oblodai.com)

</div>

---

Invoices, payouts, refunds, payout links, static wallets, webhooks, documents — the whole merchant
API, typed end to end and verified against the gateway's own contract snapshot.

- PHP ≥ 8.1, PSR-4, `declare(strict_types=1)`, readonly value objects for every response body.
- Every route the gateway exposes has a method here; request DTOs and enums are generated from the
  gateway's contract, with the English field docs your editor shows on hover.
- Retries driven by the API's own `retryable` flag, automatic idempotency keys, clock-skew correction.
- `Oblodai\Webhook\Verifier`: signature verification that needs no client and no API key.
- cURL out of the box; any PSR-18 client through `Oblodai\Http\Psr18HttpClient`.

```bash
composer require oblodai/sdk
```

Upgrading from 1.2? It signed requests the way the gateway no longer accepts — see
[MIGRATION-1.3.md](MIGRATION-1.3.md). Writing code with an AI agent? Point it at [AGENTS.md](AGENTS.md).

## Start in the sandbox

Get your keys in the Oblodai dashboard. A **sandbox key** (`test_…`) drives a chainless copy of the
gateway — fake balance from a faucet, simulated deposits, real webhooks — so integrate against it first.

```php
use Oblodai\Oblodai;

$oblodai = new Oblodai(
    publicId: getenv('OBLODAI_PUBLIC_ID'),
    secret: getenv('OBLODAI_SECRET'),
);

$invoice = $oblodai->payments->create([
    'amount' => '25',            // amounts are decimal strings, never floats
    'currency' => 'USDT',        // what you price in — a fiat (USD, EUR, …) or a crypto asset
    'network' => 'tron',         // omit to let the payer choose the network on the pay page
    'order_id' => 'order-1001',  // your reference; idempotent per order_id
    'url_callback' => 'https://shop.example/oblodai/webhook',
]);

echo $invoice->url, ' ', $invoice->address, ' ', $invoice->status->value; // "created"
```

Prices in fiat: `['amount' => '25', 'currency' => 'USD', 'to_currency' => 'USDT']` — `currency` is
what you charge, `to_currency` the asset the payer sends. See [`examples/`](examples).

Every body can also be a generated DTO, which is where the field documentation lives:

```php
use Oblodai\Contract\Enum\Network;
use Oblodai\Contract\Request\PaymentRequest;

$invoice = $oblodai->payments->create(new PaymentRequest(
    amount: '25',
    currency: 'USDT',
    network: Network::Tron,
    order_id: 'order-1001',
));
```

### Two keys

The gateway issues a **payment key** (`pk_…`) and a **payout key** (`wk_…`). Sandbox keys are both at
once; live keys are separate, and money-out routes need the payout one: `payouts`, `refunds`,
`payoutLinks`, `transfers`, `splits`, `wallets->refundBlockedDeposit()`, auto-withdraw, the IP
allow-list, `webhooks->rotateSecret()`, `sandbox->faucet()`/`reset()`. Pass both pairs and the SDK
picks the right one per call:

```php
new Oblodai(publicId: $pk, secret: $sk, payoutPublicId: $wk, payoutSecret: $ws);
// or OBLODAI_PUBLIC_ID / OBLODAI_SECRET / OBLODAI_PAYOUT_PUBLIC_ID / OBLODAI_PAYOUT_SECRET
```

A call with the wrong kind is a 403 `merchant.wrong_key_kind`.

## Resources

| Namespace                 | Methods                                                                                                                                                                                            |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `payments`                | create · info/get · cancel · history/list · batch · qr · services · sendEmail · resend · publicView · select · publicQr                                                                            |
| `refunds`                 | create · resolve · batch                                                                                                                                                                           |
| `payouts`                 | create · validate · calculate · info/get · cancel · approve · history/list · mass · batch · services · get/setFeeConfig · get/setRefundFeeConfig                                                   |
| `payoutLinks`             | create · info/get · list · cancel · batch · cheque · claimPreview · claim                                                                                                                          |
| `paymentLinks`            | create · info/get · list · toggle · publicView · checkout                                                                                                                                          |
| `batches` / `transfers`   | info · toPersonal · toUser · batch                                                                                                                                                                 |
| `wallets`                 | create · qr · block · refundBlockedDeposit                                                                                                                                                         |
| `webhooks`                | register · rotateSecret · deliveries · test · testLegacy                                                                                                                                           |
| `documents`               | statement · ledger · balanceCertificate · feeSchedule · splitReport · batchReport · linkReport · walletStatement · referralsReport · createJob · jobInfo · jobFile · download                       |
| `splits`                  | createRule · listRules · deleteRule · get/setConfig · get/setOptIn                                                                                                                                 |
| `settings`                | setDiscount · listDiscounts · get/setAccuracy · get/setAutoRefund · listAccepted · setAccepted · get/setPaymentFeeConfig · list/set/deleteAutoWithdraw · list/add/remove/enableApiAllowlist        |
| `account` / `catalog`     | balance · referral · vrcs · currencies · exchangeRates                                                                                                                                             |
| `sandbox`                 | faucet · deposit · webhooks · replay · reset                                                                                                                                                       |
| `merchants`               | create · createSandbox (provisioning; `adminToken` on a self-hosted gateway)                                                                                                                       |

Every method takes an optional last argument
`new RequestOptions(idempotencyKey: …, timeoutMs: …, deadlineMs: …, preferPayoutKey: …, headers: […])`.
Lookups accept a bare uuid or an array: `$oblodai->payments->info('uuid')`,
`$oblodai->payments->info(['order_id' => '…'])`.

### Lists

The paged list methods return a `Oblodai\Core\Page` — the first page through `items()`/`paginate()`,
every page by iterating it. Nothing is requested until you consume it. Iteration stops when the
server says `has_pages: false` or hands back a short page, whichever comes first.

A few routes are not paged and return a plain `array` instead: `settings->listAutoWithdraw()`,
`settings->setAutoWithdraw()`, `settings->deleteAutoWithdraw()`, the `*ApiAllowlist()` methods, and
the synchronous batches `payouts->mass()` / `payoutLinks->batch()` (a `list<BatchElement>` with a
per-element outcome). `payments->batch()`, `payouts->batch()`, `refunds->batch()` and
`transfers->batch()` are asynchronous and return a `BatchSubmitted` ticket to poll with
`batches->info()`.

```php
$page = $oblodai->payments->history(['limit' => 50]);
$page->items();                       // list<Payment>
$page->paginate()->total;             // total, per_page, offset, has_pages

foreach ($oblodai->payouts->history(['status' => 'confirmed']) as $payout) {
    echo $payout->uuid, "\n";         // walks page after page, lazily
}

$refunds = $oblodai->payouts->history(['kind' => 'refund'])->all(1000);
```

### Statuses

- Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
  `Status::isPaymentPaid()` is true for `paid`/`paid_over`; `wrong_amount` (underpaid) waits for
  `refunds->resolve(['uuid' => …, 'action' => 'accept'|'refund'])`; `Status::isPaymentFinal()` covers
  the rest.
- Payout: `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.

Prefer webhooks for state changes; poll `info()` only as a fallback.

Statuses (and the other closed vocabularies) decode into an open value, `Oblodai\Contract\Model\OpenEnum`:

```php
$payment->status->value;                     // "paid" — always the raw wire string
$payment->status->is(PaymentStatus::Paid);   // true
$payment->status->known;                     // PaymentStatus::Paid, or null if newer than this SDK
$payment->status->isKnown();                 // false → log it and move on
```

A value the shipped snapshot does not know never throws. The gateway adds statuses on its own
schedule, and a webhook receiver that refused the first unfamiliar one would answer 500 to an
authentic delivery and have it redelivered for a day. `Status::isPaymentPaid()` and friends simply
answer false for a status they do not recognise. To make drift loud in a test suite instead, call
`Oblodai\Contract\Model\Wire::strict()`.

Open vocabularies the gateway extends routinely — `network`, `kind`, `fee_type`, `source` — stay
plain strings, and every model keeps the untouched wire body in `->raw`.

### Errors

Every failure is an `Oblodai\Exception\OblodaiException` carrying the API's error envelope:
`errorCode` (`payout.insufficient_funds`), `httpStatus`, `retryable`, `retryAfter`, `requestId`,
`field`, `synthetic`. Subclasses for `catch`: `ValidationException` (400), `AuthenticationException`
(401), `PermissionException` (403), `NotFoundException` (404), `ConflictException` /
`IdempotencyConflictException` (409), `RateLimitException` (429), `UnavailableException` (503),
`InternalException` (other 5xx), `TransportException` (no response), `ConfigException` (rejected
before sending), `ContractException` (unreadable envelope), `WebhookPayloadException` (a verified
webhook whose body is not the documented object). Quote `requestId` to support.

The SDK's own codes, all raised before or instead of an API answer: `sdk.missing_credentials`,
`sdk.bad_config`, `sdk.bad_header`, `sdk.bad_path_param`, `sdk.bad_amount`,
`sdk.bad_idempotency_key`, `sdk.idempotency_unsupported` (all `ConfigException`),
`sdk.bad_envelope` and `webhook.bad_payload` (`ContractException`), `sdk.response_too_large`,
`transport.timeout`, `transport.network`, `transport.deadline` (`TransportException`).

```php
use Oblodai\Exception\OblodaiException;

try {
    $payout = $oblodai->payouts->create($params);
} catch (OblodaiException $err) {
    match ($err->errorCode) {
        // retryable — the balance may still arrive
        'payout.insufficient_funds', 'payout.funds_maturing' => scheduleRetry($err->retryAfter ?? 60),
        default => throw $err, // the SDK already retried what was safe to retry
    };
}
```

`json_encode($err)` keeps the message and the classification and drops the raw body.

### Retries and idempotency

- Create-type routes get an `Idempotency-Key` automatically (one per logical call, reused on every
  retry), so a timeout can never produce a second payout. Pass your own key to make retries safe
  across restarts; on routes the gateway does not deduplicate the SDK refuses a key
  (`sdk.idempotency_unsupported`).
- An error is retried only when the API says `retryable: true`. Answers without an API envelope (a
  proxy 502/503) and transport failures are retried only on read routes or keyed writes.
  `Retry-After` is honoured.
- Which routes are safe to re-send after a transport failure is the gateway's own answer, shipped in
  the contract as `safe` per route (`Routes::SPECS`). The SDK never infers it from the path.
- `retry: new Retry(maxRetries: 2, baseDelayMs: 250, maxDelayMs: 4000, maxRetryAfterMs: 30000)`;
  `timeoutMs` per attempt, `deadlineMs` per call. `$err->retryAfter` reports what the gateway asked
  for (clamped to a day); the SDK's own sleep is capped by `maxRetryAfterMs`.

### Webhooks

```php
use Oblodai\Contract\Model\PaymentEvent;
use Oblodai\Webhook\Verifier;

$delivery = Verifier::verify(
    rawBody: file_get_contents('php://input'),   // the RAW bytes, never a re-encoded parse
    headers: getallheaders(),
    secret: getenv('OBLODAI_WEBHOOK_SECRET'),
);

$event = $delivery->event;                        // PaymentEvent | PayoutEvent | WalletEvent | UnknownEvent
if ($delivery->isTest) {                          // a rehearsal delivery — no money moved
    http_response_code(200);
    return;
}
if ($event instanceof PaymentEvent && $event->status->is('paid')) {
    markOrderPaid($event->order_id);
}
http_response_code(200);
```

Answer the right status to the right failure:

| exception                   | what happened                                  | answer                |
| --------------------------- | ---------------------------------------------- | --------------------- |
| `ConfigException`           | your receiver is misconfigured (no secret)     | 500, and fix it       |
| `SignatureException`        | not our delivery, or too old                   | 401                   |
| `WebhookPayloadException`   | our delivery, unreadable body                  | 2xx (or 400) + alert  |

`webhook.bad_payload` is deliberately NOT in the signature family: the MAC already proved the event
is authentic, and answering 401 would make the gateway redeliver it for a day. An event `type` this
SDK does not model is not a failure either — it arrives as `UnknownEvent` with the raw body intact,
and `Verifier::isKnownEvent($event)` tells you which you have.

Rehearsal deliveries (`webhooks->test()`, sandbox) are signed exactly like live ones and carry
`test: true` in the body (and `X-Webhook-Test: true`) — check `$delivery->isTest` (or
`Verifier::isTestEvent($event)`) and never act on one as if money moved.
`$delivery->id` (`X-Webhook-Id`) is stable across retries — use it to deduplicate;
`$event->sequence()` orders events (`Verifier::isStale()`, which is false for an event that carries
no sequence). After `webhooks->rotateSecret()` pass `previousSecret:` for at least 26 hours.

The secret is checked before any crypto: an empty `secret` (or an empty `previousSecret`, or a
negative `toleranceSec`) is a `ConfigException`, never a verification against `HMAC('', body)`.
`toleranceSec: 0` disables the freshness window. The signature is compared BEFORE the timestamp, so
the window cannot be used to probe your clock.

### Money helpers

`Oblodai\Helper\Money::add()`, `subtract()`, `compare()`, `equals()`, `isZero()`, `isPositive()`,
`assertAmount()` — exact decimal arithmetic on the string amounts the API uses. Never cast a money
field to `float`, and never compare amounts as strings (`"9"` sorts after `"10"` as text and before
it as money — use `compare()`). Anything that is not a decimal amount of at most 64 characters is a
`ConfigException` (`sdk.bad_amount`); the SDK also refuses a float in a request body outright.

### HTTP stack

cURL is the default. To reuse your own client, wrap any PSR-18 implementation:

```php
use Oblodai\Http\Psr18HttpClient;

new Oblodai(publicId: $pk, secret: $sk, http: new Psr18HttpClient($client, $requestFactory, $streamFactory));
```

PSR-18 describes only "send this request, get a response", so three things must be configured on the
client itself:

- **no redirects** (`allow_redirects: false` in Guzzle, `max_redirects: 0` in Symfony) — the
  signature covers the path that was requested, and PSR-18 gives the SDK no way to see which URL
  actually answered;
- **timeouts**, connect and total — the SDK's `timeoutMs` cannot be applied here, only the overall
  per-call deadline;
- **TLS verification left on**.

`CurlHttpClient` (the default) enforces all three itself and cannot be talked out of them through
`$curlOptions`. Both clients read the response body with a ceiling — 8 MiB on JSON routes, 64 MiB on
document routes — and refuse anything larger (`sdk.response_too_large`).

### Self-hosted or local gateway

`baseUrl: 'http://localhost:8093'` works out of the box; other plain-http hosts need
`allowInsecureBaseUrl: true` (or `OBLODAI_ALLOW_INSECURE=1`). A path prefix in `baseUrl` is kept.
Provisioning routes (`merchants->create()`, `merchants->createSandbox()`) need the gateway's
`adminToken:` (or `OBLODAI_ADMIN_TOKEN`), which is sent as `X-Admin-Token` on those routes only.

### Environment

| variable                    | what it sets                                                        |
| --------------------------- | ------------------------------------------------------------------- |
| `OBLODAI_PUBLIC_ID`         | payment key's public id                                             |
| `OBLODAI_SECRET`            | payment key's secret                                                |
| `OBLODAI_PAYOUT_PUBLIC_ID`  | payout key's public id                                              |
| `OBLODAI_PAYOUT_SECRET`     | payout key's secret                                                 |
| `OBLODAI_BASE_URL`          | API origin, default `https://api.oblodai.com`; a path prefix is kept |
| `OBLODAI_ADMIN_TOKEN`       | admin token for the provisioning routes of a self-hosted gateway    |
| `OBLODAI_ALLOW_INSECURE`    | `1` permits a plain-http `baseUrl` that is not loopback             |
| `OBLODAI_LOG`               | `debug`\|`info`\|`warn`\|`error` — logs to STDERR                    |

An empty value counts as unset. Explicit constructor arguments always win over the environment, and
`env: []` in the constructor ignores it entirely (used by the test suite).

Secrets never reach a log. Credentials and the admin token keep their value off the object itself,
so `print_r`/`var_dump`/`json_encode`/`serialize` of the client, its config or its transport show
`[redacted]`; the models that carry a one-time secret (`WebhookEndpoint`, `WebhookSecretRotated`,
`ApiKeyPair`, `MerchantOnboarded`, `PayoutLink` — `claim_token`, `claim_url` and `passcode`, the
URL because it embeds the token) mask it in every wholesale rendering while the property stays
readable. Whatever logger you inject is wrapped, so redaction happens before the SDK hands
anything over.

## The contract snapshot

`contract/` is exported by the gateway's own test suite: the route registry (107 merchant routes,
each with its auth gate, idempotency wrapper and hand-classified `safe` flag), request DTO schemas
with English field docs, enums, every error code (471), signing vectors, golden response bodies
recorded from a live gateway and real signed webhook deliveries. `src/Contract/{Routes,Enums,Version}.php`,
`src/Contract/Enum/*` and `src/Contract/Request/*` are generated from it (`composer codegen`);
`composer check-drift` fails when they disagree; the contract test tier checks every model against
the golden bodies.

## Development

```bash
composer install
composer ci          # check-drift + lint + stan (level max) + test (unit + contract)
composer codegen     # after refreshing contract/
OBLODAI_LIVE_URL=http://127.0.0.1:8095 composer test-live   # the journey against a real gateway
```

License: MIT.
