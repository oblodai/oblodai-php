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

[Documentation](https://docs.oblodai.com) · [Dashboard](https://my.oblodai.com) · [Читать по-русски →](README.ru.md)

</div>

---

The official PHP SDK for the **Oblodai** payment gateway: accepting payments, payouts, bulk
operations (batches), payment links, payout links (crypto cheques), splits, static wallets,
transfers, webhooks. Request signing, response parsing, typed errors, idempotency and retries — out
of the box.

PHP ≥ 8.1 with `ext-json` and `ext-curl`, PSR-4 and `declare(strict_types=1)` throughout, a readonly
value object for every response body, and nothing else at runtime but the PSR HTTP interfaces: cURL
is used out of the box, and any PSR-18 client can take its place.

> **Base URL.** Defaults to `https://api.oblodai.com`. Override `baseUrl` and supply your own keys
> at initialisation if needed. The scheme must be `https://`; plain `http://` is accepted only for
> loopback (`http://127.0.0.1:8095`) or with the explicit allow-insecure option
> (`allowInsecureBaseUrl: true`, or `OBLODAI_ALLOW_INSECURE=1`).

## Installation

```bash
composer require oblodai/sdk
```

PHP 8.1 or newer with `ext-json` and `ext-curl`. Composer pulls in the PSR HTTP interfaces
(`psr/http-client`, `psr/http-factory`, `psr/http-message`); `psr/log` is optional and only needed
to route the SDK's log through Monolog or another PSR-3 logger.

## Where to get keys

A merchant has **one** API key, issued in the dashboard at
[my.oblodai.com](https://my.oblodai.com) → **API keys**. It is a public id and a secret; the secret
only ever signs a request, it is never sent.

| key              | public id             | secret                | what it opens                                                                                  |
| ---------------- | --------------------- | --------------------- | ---------------------------------------------------------------------------------------------- |
| **live API key** | `oblodai_<hex>`       | `oblodai_live_<hex>`  | the whole merchant API: money in, money out, settings, documents                                |
| **sandbox key**  | `test_oblodai_<hex>`  | `oblodai_test_<hex>`  | the same API against the sandbox; minted by sandbox onboarding                                  |
| **admin token**  | —                     | —                     | provisioning on a **self-hosted** gateway: `merchants->create()`, `merchants->createSandbox()`  |

That one pair signs every route the gateway gates — there is nothing to choose per call:

```php
use Oblodai\Oblodai;

$oblodai = new Oblodai(publicId: $publicId, secret: $secret);
```

The admin token is not a merchant key at all — it is sent as `X-Admin-Token` on the two onboarding
routes only, and only a gateway you host yourself has one.

Accounts opened before the single-key change may still hold a **legacy split pair**: an
`oblodai_pk_…` payment key and an `oblodai_wk_…` payout key. Each half still works on its own half
of the API, so build one client per key; only such a pair can ever see a 403
`merchant.wrong_key_kind` (the payout half signed with the payment key, or the reverse). Every key
issued today is a single `oblodai_…`.

## Quick start

```php
use Oblodai\Oblodai;

// Credentials fall back to OBLODAI_PUBLIC_ID / OBLODAI_SECRET.
$oblodai = new Oblodai();

$invoice = $oblodai->payments->create([
    'amount' => '25',            // amounts are decimal strings, never floats
    'currency' => 'USDT',        // what you price in — a fiat (USD, EUR, …) or a crypto asset
    'network' => 'tron',         // omit to let the payer choose the network on the pay page
    'order_id' => 'order-1001',  // your reference; the invoice is idempotent per order_id
    'url_callback' => 'https://shop.example/oblodai/webhook',
]);

echo $invoice->url, ' ', $invoice->address, ' ', $invoice->status->value; // "created"
```

To price in fiat, add `to_currency`: `['amount' => '25', 'currency' => 'USD', 'to_currency' =>
'USDT']` — `currency` is what you charge, `to_currency` the asset the payer sends.

Money out is the same shape — the same key signs it — with an idempotency key of your own so a
retry after a restart cannot send twice:

```php
use Oblodai\Core\RequestOptions;

$payout = $oblodai->payouts->create([
    'amount' => '10',
    'currency' => 'USDT',
    'network' => 'tron',
    'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx',
    'order_id' => 'payout-1001',
], new RequestOptions(idempotencyKey: 'payout-1001'));

echo $payout->uuid, ' ', $payout->status->value; // "pending"
```

Every request body can also be a generated DTO, which is where the field documentation your editor
shows on hover lives:

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

Runnable versions of all of this are in [`examples/`](examples).

## Sandbox / testing

A sandbox key (`test_oblodai_…`) drives a chainless copy of the gateway: fake balance from a faucet,
simulated deposits, real signed webhooks. Integrate against it first — nothing here touches a chain.

```php
// The buyer pays: repeat the same txid to add confirmations.
$oblodai->sandbox->deposit([
    'invoice_id' => $invoice->uuid,
    'amount' => '25',
    'confirmations' => 20,
    'txid' => 'sandbox-tx-1',
]);

$oblodai->sandbox->faucet(['asset' => 'USDT', 'amount' => '100']);   // test funds

foreach ($oblodai->sandbox->webhooks(['limit' => 10])->items() as $delivery) {
    echo $delivery->event_type, ' ', $delivery->status->value, "\n";  // the webhook inspector
}

$oblodai->sandbox->replay($deliveryId);   // re-send a terminal (delivered/dead) delivery
$oblodai->sandbox->reset();               // cancel open invoices, zero the balances
```

A rehearsal delivery can also be requested against live: `webhooks->test(WebhookKind::Payment,
['url_callback' => …, 'status' => 'paid'])` sends a sample event signed exactly like a real one,
with `test: true` in the body. Never let one move money in your system — see
[Webhooks](#webhooks).

A live key answers 403 `sandbox.live_key` on the sandbox helpers.

## Method overview

Sixteen namespaces, 107 routes — every merchant route the gateway exposes.

| namespace       | methods                                                                                                                                                                                   | routes                                                          |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| `payments`      | `create` · `info`/`get` · `cancel` · `history`/`list` · `batch` · `qr` · `services` · `sendEmail` · `resend` · `publicView` · `select` · `publicQr`                                        | 12 — `/v1/payment`, `/v1/payment/*`, `/v1/pay/{id}*`             |
| `refunds`       | `create` · `resolve` · `batch`                                                                                                                                                            | 3 — `/v1/payment/refund`, `/v1/payment/resolve`, `/v1/refund/batch` |
| `payouts`       | `create` · `validate` · `calculate` · `info`/`get` · `cancel` · `approve` · `history`/`list` · `mass` · `batch` · `services` · `get`/`setFeeConfig` · `get`/`setRefundFeeConfig`           | 14 — `/v1/payout`, `/v1/payout/*`                               |
| `payoutLinks`   | `create` · `info`/`get` · `list` · `cancel` · `batch` · `cheque` · `claimPreview` · `claim`                                                                                                | 8 — `/v1/payout/link*`, `/v1/claim/{token}`                     |
| `paymentLinks`  | `create` · `info`/`get` · `list` · `toggle` · `publicView` · `checkout`                                                                                                                    | 6 — `/v1/payment/link*`, `/v1/link/{id}*`                       |
| `batches`       | `info`                                                                                                                                                                                    | 1 — `/v1/batch/info`                                            |
| `transfers`     | `toPersonal` · `toUser` · `batch`                                                                                                                                                         | 3 — `/v1/transfer/*`                                            |
| `wallets`       | `create` · `qr` · `block` · `refundBlockedDeposit`                                                                                                                                        | 4 — `/v1/wallet`, `/v1/wallet/*`                                |
| `webhooks`      | `register` · `rotateSecret` · `deliveries` · `test` · `testLegacy`                                                                                                                        | 7 — `/v1/webhooks*`, `/v1/test-webhook/{kind}`                  |
| `documents`     | `statement` · `ledger` · `balanceCertificate` · `feeSchedule` · `splitReport` · `batchReport` · `linkReport` · `walletStatement` · `referralsReport` · `createJob` · `jobInfo` · `jobFile` · `download` | 13 — `/v1/documents/*`                             |
| `splits`        | `createRule` · `listRules` · `deleteRule` · `get`/`setConfig` · `get`/`setOptIn`                                                                                                          | 7 — `/v1/split/*`                                               |
| `settings`      | `setDiscount` · `listDiscounts` · `get`/`setAccuracy` · `get`/`setAutoRefund` · `listAccepted` · `setAccepted` · `get`/`setPaymentFeeConfig` · `list`/`set`/`deleteAutoWithdraw` · `list`/`add`/`remove`/`enableApiAllowlist` | 17 — `/v1/payment/{accepted,accuracy,autorefund,discount,fee-config}/*`, `/v1/auto-withdraw/*`, `/v1/api-allowlist/*` |
| `account`       | `balance` · `referral` · `vrcs`                                                                                                                                                           | 3 — `/v1/balance`, `/v1/referral/info`, `/v1/vrcs`              |
| `catalog`       | `currencies` · `exchangeRates`                                                                                                                                                            | 2 — `/v1/currencies`, `/v1/exchange-rate/list`                  |
| `sandbox`       | `faucet` · `deposit` · `webhooks` · `replay` · `reset`                                                                                                                                    | 5 — `/v1/sandbox/*`                                             |
| `merchants`     | `create` · `createSandbox`                                                                                                                                                                | 2 — `/v1/merchants`, `/v1/merchants/{id}/sandbox`               |

Every method takes an optional last argument
`new RequestOptions(idempotencyKey: …, timeoutMs: …, deadlineMs: …, headers: […])`.
Lookups accept a bare uuid or an array: `$oblodai->payments->info('uuid')`,
`$oblodai->payments->info(['order_id' => '…'])`.

### Lists

The paged list methods return an `Oblodai\Core\Page` — the first page through `items()`/`paginate()`,
every page by iterating it. Nothing is requested until you consume it. Iteration stops when the
server says `has_pages: false` or hands back a short page, whichever comes first.

```php
$page = $oblodai->payments->history(['limit' => 50]);
$page->items();                       // list<Payment>
$page->paginate()->total;             // total, per_page, offset, has_pages

foreach ($oblodai->payouts->history(['status' => 'confirmed']) as $payout) {
    echo $payout->uuid, "\n";         // walks page after page, lazily
}

$refunds = $oblodai->payouts->history(['kind' => 'refund'])->all(1000);
```

A few routes are not paged and return a plain `array` instead: `settings->listAutoWithdraw()`,
`settings->setAutoWithdraw()`, `settings->deleteAutoWithdraw()`, the `*ApiAllowlist()` methods, and
the synchronous batches `payouts->mass()` (≤100) / `payoutLinks->batch()` (≤500), which give a
`list<BatchElement>` with a per-element outcome. `payments->batch()`, `payouts->batch()`,
`refunds->batch()` and `transfers->batch()` are asynchronous (≤5000) and return a `BatchSubmitted`
ticket to poll with `batches->info()`.

### Statuses

- Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
  `Status::isPaymentPaid()` is true for `paid`/`paid_over`; `wrong_amount` (underpaid) waits for
  `refunds->resolve(['uuid' => …, 'action' => 'accept'|'refund'])`; `Status::isPaymentFinal()` covers
  the rest.
- Payout: `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.

Prefer webhooks for state changes; poll `info()` only as a fallback.

Statuses (and the other closed vocabularies) decode into an open value,
`Oblodai\Contract\Model\OpenEnum`:

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

### Money

`Oblodai\Helper\Money::add()`, `subtract()`, `compare()`, `equals()`, `isZero()`, `isPositive()`,
`assertAmount()` — exact decimal arithmetic on the string amounts the API uses. Never cast a money
field to `float`, and never compare amounts as strings (`"9"` sorts after `"10"` as text and before
it as money — use `compare()`). Anything that is not a decimal amount of at most 64 characters is a
`ConfigException` (`sdk.bad_amount`); the SDK also refuses a float in a request body outright.

## Webhooks

Register an endpoint with `webhooks->register($url)` — the signing secret is returned once, so store
it then. Verify every delivery over the **raw** request bytes; a re-serialised parse will not match.

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

`Verifier` needs no client and no API key. Answer the right status to the right failure:

| exception                   | what happened                                  | answer                |
| --------------------------- | ---------------------------------------------- | --------------------- |
| `ConfigException`           | your receiver is misconfigured (no secret)     | 500, and fix it       |
| `SignatureException`        | not our delivery, or too old                   | 401                   |
| `WebhookPayloadException`   | our delivery, unreadable body                  | 2xx (or 400) + alert  |

`webhook.bad_payload` is deliberately NOT in the signature family: the MAC already proved the event
is authentic, and answering 401 would make the gateway redeliver it for a day. **401 is for a
signature failure and nothing else.** An event `type` this SDK does not model is not a failure
either — it arrives as `UnknownEvent` with the raw body intact, and `Verifier::isKnownEvent($event)`
tells you which you have.

Rehearsal deliveries (`webhooks->test()`, sandbox) are signed exactly like live ones and carry
`test: true` in the body (and `X-Webhook-Test: true`) — check `$delivery->isTest` (or
`Verifier::isTestEvent($event)`) and never act on one as if money moved.
`$delivery->id` (`X-Webhook-Id`) is stable across retries — use it to deduplicate;
`$event->sequence()` orders events (`Verifier::isStale()`, which is false for an event that carries
no sequence). After `webhooks->rotateSecret()` pass `previousSecret:` for at least 26 hours, until
the `previous_secret_valid_until` the rotation returned.

The secret is checked before any crypto: an empty `secret` (or an empty `previousSecret`, or a
negative `toleranceSec`) is a `ConfigException`, never a verification against `HMAC('', body)`.
The freshness window is 300 seconds by default; `toleranceSec: 0` disables it. The signature is
compared BEFORE the timestamp, so the window cannot be used to probe your clock.

## Errors

Every failure is an `Oblodai\Exception\OblodaiException` carrying the API's error envelope:
`errorCode` (`payout.insufficient_funds`), `httpStatus`, `retryable`, `retryAfter`, `requestId`,
`field`, `synthetic` (the answer came from a proxy, not the API). Quote `requestId` to support;
`json_encode($err)` keeps the message and the classification and drops the raw body.

| class                                             | HTTP        | when                                            |
| ------------------------------------------------- | ----------- | ----------------------------------------------- |
| `ValidationException`                             | 400         | the request body is wrong (`field` says where)  |
| `AuthenticationException`                         | 401         | bad signature, unknown key, stale timestamp     |
| `PermissionException`                             | 403         | valid key, but the call is not allowed          |
| `NotFoundException`                               | 404         | no such object                                  |
| `ConflictException` / `IdempotencyConflictException` | 409       | state conflict; a key reused with another body  |
| `RateLimitException`                              | 429         | throttled — `retryAfter` says how long          |
| `UnavailableException`                            | 503         | gateway busy or frozen; retryable               |
| `InternalException`                               | other 5xx   | gateway fault                                   |
| `TransportException`                              | —           | no response at all (timeout, network, deadline) |
| `ConfigException`                                 | —           | rejected before anything was sent               |
| `ContractException`                               | —           | unreadable envelope or webhook body             |
| `SignatureException`                              | —           | webhook verification failed                     |

`retryable` is authoritative: the SDK already retried whatever it should have, so a `retryable`
error that reaches you is one repeating is allowed to fix but the SDK ran out of attempts or budget
for. Branch on `errorCode` — `family.reason`, with the full catalogue of 469 codes in
`Oblodai\Contract\Enums::ERROR_CODES`, and the codes worth handling named in each money-moving
method's docblock:

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

The SDK's own codes never come from the API — they are raised before or instead of an answer:
`sdk.missing_credentials`, `sdk.bad_config`, `sdk.bad_header`, `sdk.bad_path_param`,
`sdk.bad_amount`, `sdk.bad_idempotency_key`, `sdk.idempotency_unsupported` (all `ConfigException`),
`sdk.bad_envelope` and `webhook.bad_payload` (`ContractException`), `sdk.response_too_large`,
`transport.timeout`, `transport.network`, `transport.deadline` (`TransportException`).

## Retries, idempotency and timeouts

- **Safe to repeat** is the gateway's own answer, not a guess from the path: every route ships in
  the contract with a hand-classified `safe` flag (`Oblodai\Contract\Routes::SPECS`). The SDK never
  infers it.
- An error is retried only when the API says `retryable: true`. Answers without an API envelope (a
  proxy 502/503) and transport failures are retried only on read routes or keyed writes — a write
  the gateway does not deduplicate is never re-sent once it may have arrived.
- **Idempotency keys** are generated automatically on create routes (one per logical call, reused on
  every retry), so a timeout can never produce a second payout. Pass your own key to make retries
  safe across restarts too. On a route the gateway does not deduplicate the SDK refuses a key
  (`sdk.idempotency_unsupported`); a key reused with a different body is a 409
  `idempotency.key_reused`. List pages never carry a caller's key.
- **Per-call options:** `new RequestOptions(idempotencyKey: …, timeoutMs: …, deadlineMs: …,
  headers: […])`. Per-call headers merge over the client's, case-insensitively; nothing the SDK
  signs can be overridden from there.
- **Policy:** `retry: new Retry(maxRetries: 2, baseDelayMs: 250, maxDelayMs: 4000, maxRetryAfterMs:
  30000)` — the defaults; `new Retry(maxRetries: 0)` disables retries. `timeoutMs` (default 30000)
  bounds one attempt, `deadlineMs` (default 90000) the whole call including pauses. `Retry-After`
  always wins over the computed backoff; `$err->retryAfter` reports what the gateway asked for
  (clamped to a day) while the SDK's own sleep is capped by `maxRetryAfterMs`.
- **Clock skew** is corrected once per call: if the gateway rejects the timestamp, the SDK learns
  the server's time from the `Date` header and re-signs, then keeps the offset for later calls.
- **Redirects are never followed** — the signature covers the path that was requested — and the
  response body is read with a ceiling: 8 MiB on JSON routes, 64 MiB on document routes, above which
  the call fails with `sdk.response_too_large`.

## Configuration

```php
use Oblodai\Core\Retry;
use Oblodai\Oblodai;

$oblodai = new Oblodai(
    publicId: $pk,
    secret: $sk,
    baseUrl: 'https://api.oblodai.com',
    timeoutMs: 30000,
    deadlineMs: 90000,
    retry: new Retry(maxRetries: 2),
);
```

| option                 | default                     | what it does                                                     |
| ---------------------- | --------------------------- | ---------------------------------------------------------------- |
| `publicId` / `secret`  | environment                 | the API key; the secret only ever signs                          |
| `baseUrl`              | `https://api.oblodai.com`   | API origin; a path prefix is kept                                |
| `http`                 | `CurlHttpClient`            | custom HTTP stack — see `Psr18HttpClient`                        |
| `timeoutMs`            | `30000`                     | per-attempt timeout                                              |
| `deadlineMs`           | `90000`                     | overall budget per call, retries and pauses included             |
| `retry`                | `new Retry()`               | retry policy; `new Retry(maxRetries: 0)` turns retries off       |
| `logger`               | none                        | structured logger; `OBLODAI_LOG` picks a console one             |
| `headers`              | `[]`                        | extra headers on every request                                   |
| `adminToken`           | environment                 | admin token of a self-hosted gateway (onboarding routes)         |
| `allowInsecureBaseUrl` | `false`                     | permit a plain-http `baseUrl` that is not loopback               |
| `clock`, `env`         | real clock, real environment | injectable, for tests                                           |

| variable                    | what it sets                                                        |
| --------------------------- | ------------------------------------------------------------------- |
| `OBLODAI_PUBLIC_ID`         | the API key's public id                                             |
| `OBLODAI_SECRET`            | the API key's secret                                                |
| `OBLODAI_ADMIN_TOKEN`       | admin token for the provisioning routes of a self-hosted gateway    |
| `OBLODAI_BASE_URL`          | API origin, default `https://api.oblodai.com`; a path prefix is kept |
| `OBLODAI_LOG`               | `debug`\|`info`\|`warn`\|`error` — logs to STDERR                    |
| `OBLODAI_ALLOW_INSECURE`    | `1` permits a plain-http `baseUrl` that is not loopback             |

An empty value counts as unset. Explicit constructor arguments always win over the environment, and
`env: []` in the constructor ignores it entirely (used by the test suite).

**Secrets never reach a log.** Credentials and the admin token keep their value off the object
itself, so `print_r`/`var_dump`/`json_encode`/`serialize` of the client, its config or its transport
show `[redacted]`; the models that carry a one-time secret (`WebhookEndpoint`,
`WebhookSecretRotated`, `ApiKeyPair`, `MerchantOnboarded`, `PayoutLink` — `claim_token`, `claim_url`
and `passcode`, the URL because it embeds the token) mask it in every wholesale rendering while the
property stays readable. Whatever logger you inject is wrapped, so redaction happens before the SDK
hands anything over.

### HTTP stack

cURL is the default. To reuse your own client, wrap any PSR-18 implementation:

```php
use Oblodai\Http\Psr18HttpClient;

$oblodai = new Oblodai(
    publicId: $pk,
    secret: $sk,
    http: new Psr18HttpClient($client, $requestFactory, $streamFactory),
);
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
`$curlOptions`.

### Self-hosted or local gateway

`baseUrl: 'http://localhost:8093'` works out of the box; any other plain-http host needs
`allowInsecureBaseUrl: true` (or `OBLODAI_ALLOW_INSECURE=1`). A path prefix in `baseUrl` is kept.
The provisioning routes `merchants->create()` and `merchants->createSandbox()` need the gateway's
`adminToken:` (or `OBLODAI_ADMIN_TOKEN`), which is sent as `X-Admin-Token` on those routes only.

## The contract snapshot

`contract/` is exported by the gateway's own test suite: the route registry (107 merchant routes,
each with its auth gate — `public`, `key` or `onboard` — idempotency wrapper and hand-classified
`safe` flag), request DTO schemas
with English field docs, enums, every error code (469), signing vectors, golden response bodies
recorded from a live gateway and real signed webhook deliveries.

`src/Contract/{Routes,Enums,Version}.php`, `src/Contract/Enum/*` and `src/Contract/Request/*` are
generated from it with `composer codegen`; `composer check-drift` fails when they disagree, and the
contract test tier checks every model against the golden bodies. Which snapshot a release carries is
in `Oblodai\Contract\Version` — `CORE_COMMIT`, `EXPORTED_AT`, `CONTRACT_HASH`; this one ships core
`2cc44c16`. To refresh: drop the new export into `contract/`, run `composer codegen`, then
`composer ci`.

## Development

```bash
composer install
composer ci          # check-drift + lint + stan (level max) + test (unit + contract)
composer fmt         # apply the formatting `composer lint` checks
composer codegen     # after refreshing contract/
OBLODAI_LIVE_URL=http://127.0.0.1:8095 composer test-live   # the journey against a real gateway
```

The live tier is skipped unless `OBLODAI_LIVE_URL` points at a running gateway; it provisions its
own merchant, mints a `test_oblodai_…` key and spends only fake money.

Writing code with an AI agent? Point it at [AGENTS.md](AGENTS.md). Upgrading from 1.2 — which signed
requests the way the gateway no longer accepts — see [MIGRATION-1.3.md](MIGRATION-1.3.md); the
release history is in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
