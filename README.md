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

[Documentation](https://docs.oblodai.com) · [Dashboard](https://my.oblodai.com) · [Read in Russian →](README.ru.md)

</div>

---

The official PHP SDK for the **Oblodai** payment gateway: accepting payments, payouts, bulk
operations (batches), payment links, payout links (crypto cheques), splits, static wallets,
transfers, webhooks. Request signing, response parsing, typed errors, idempotency and retries — out
of the box.

PHP ≥ 8.2 with `ext-json` and `ext-curl`, PSR-4 and `declare(strict_types=1)` throughout, a readonly
model for every request and response body, and nothing else at runtime but the PSR HTTP interfaces:
cURL is used out of the box, and any PSR-18 client can take its place. The resources, methods and
models are generated from the gateway's OpenAPI contract, so the SDK always speaks the API it ships
against.

> **Base URL.** Defaults to `https://api.oblodai.com`. Override `baseUrl` and supply your own keys
> at initialisation if needed. The scheme must be `https://`; plain `http://` is accepted only for
> loopback (`http://127.0.0.1:8095`) or with the explicit allow-insecure option
> (`allowInsecureBaseUrl: true`, or `OBLODAI_ALLOW_INSECURE=1`).

## Installation

```bash
composer require oblodai/sdk
```

PHP 8.2 or newer with `ext-json` and `ext-curl`. Composer pulls in the PSR HTTP interfaces
(`psr/http-client`, `psr/http-factory`, `psr/http-message`); `psr/log` is optional and only needed
to route the SDK's log through Monolog or another PSR-3 logger. Upgrading from 1.x: see
[MIGRATION-2.0.md](MIGRATION-2.0.md).

## Where to get keys

A merchant has **one** API key, issued in the dashboard at
[my.oblodai.com](https://my.oblodai.com) → **API keys**. It is a public id and a secret; the secret
only ever signs a request, it is never sent.

| key              | public id             | secret                | what it opens                                                      |
| ---------------- | --------------------- | --------------------- | ------------------------------------------------------------------ |
| **live API key** | `oblodai_<hex>`       | `oblodai_live_<hex>`  | the whole merchant API: money in, money out, settings, documents   |
| **sandbox key**  | `test_oblodai_<hex>`  | `oblodai_test_<hex>`  | the same API against the sandbox                                   |
| **admin token**  | —                     | —                     | provisioning on a **self-hosted** gateway: `sandbox->onboardStore()` |

That one pair signs every route the gateway gates — there is nothing to choose per call:

```php
use Oblodai\Oblodai;

$oblodai = new Oblodai(publicId: $publicId, secret: $secret);
```

## Quick start

```php
use Oblodai\Helper\Status;

// Credentials fall back to OBLODAI_PUBLIC_ID / OBLODAI_SECRET.
$oblodai = new Oblodai();

$invoice = $oblodai->payments->create([
    'amount' => '25',            // amounts are decimal strings, never floats
    'currency' => 'USDT',        // what you price in — a fiat (USD, EUR, …) or a crypto asset
    'network' => 'tron',         // omit to let the payer choose the network on the pay page
    'order_id' => 'order-1001',  // your reference; the invoice is idempotent per order_id
    'url_callback' => 'https://shop.example/oblodai/webhook',
]);

echo $invoice->url, ' ', $invoice->address, ' ', Status::value($invoice->status), "\n"; // "created"
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

echo $payout->uuid, ' ', Status::value($payout->status), "\n"; // "pending"
```

A request body is an array (its shape is in the method's docblock, so your editor completes the
keys) or the generated request model, whose named arguments complete too:

```php
use Oblodai\Generated\Model\PaymentRequest;

$invoice = $oblodai->payments->create(new PaymentRequest(
    amount: '25',
    currency: 'USDT',
    network: 'tron',
    order_id: 'order-1002',
));
```

Runnable versions of all of this are in [`examples/`](examples); the test suite runs them, and every
code block of this README, against a mock gateway.

## Sandbox / testing

A sandbox key (`test_oblodai_…`) drives a chainless copy of the gateway: fake balance from a faucet,
simulated deposits, real signed webhooks. Integrate against it first — nothing here touches a chain.

```php
// The buyer pays: repeat the same txid to add confirmations.
$oblodai->sandbox->simulateDeposit([
    'invoice_id' => $invoice->uuid,
    'amount' => '25',
    'confirmations' => 20,
    'txid' => 'sandbox-tx-1',
]);

$oblodai->sandbox->faucet(['asset' => 'USDT', 'amount' => '100']);   // test funds

foreach ($oblodai->sandbox->listWebhooks(limit: 10)->items() as $delivery) {
    echo $delivery->event_type, ' ', Status::value($delivery->status), "\n";   // the webhook inspector
    $oblodai->sandbox->replayWebhook(['delivery_id' => $delivery->id]);        // re-send a finished one
}

$oblodai->sandbox->reset();   // cancel open invoices, zero the balances
```

A rehearsal delivery can also be requested against live: `webhooks->sendTestPayment(['url_callback'
=> …, 'status' => 'paid'])` sends a sample event signed exactly like a real one, with `test: true`
in the body. Never let one move money in your system — see [Webhooks](#webhooks). A live key
answers 403 `sandbox.live_key` on the sandbox helpers.

## Method overview

Every operation of the gateway's contract, as `$oblodai-><namespace>-><method>()`, named after its
`operationId` (the 1.x names are in [MIGRATION-2.0.md](MIGRATION-2.0.md)). The table is written by
the generator:

<!-- sdkgen:methods -->
17 resources, 123 methods.

| Resource | Methods |
| --- | --- |
| `payments` | `create` · `getInfo` · `getQr` · `listHistory` · `listServices` · `cancel` · `sendEmail` · `setCheckoutConfig` · `getCheckoutConfig` · `getAmlLinks` · `resolve` |
| `paymentLinks` | `create` · `list` · `get` · `toggle` |
| `refunds` | `payment` · `blockedWallet` |
| `payouts` | `create` · `createMass` · `getInfo` · `listHistory` · `calculate` · `validate` · `cancel` · `approve` · `listServices` · `transferToPersonal` · `transferToUser` · `createTransferBatch` |
| `payoutLinks` | `create` · `createBatch` · `list` · `get` · `cancel` · `getPayoutClaim` · `claimPayout` |
| `batches` | `createPayment` · `createRefund` · `createPayout` · `getInfo` |
| `splits` | `createRule` · `listRules` · `deleteRule` · `setConfig` · `getConfig` · `setRecipientOptIn` · `getRecipientOptIn` |
| `wallets` | `create` · `block` · `getQr` |
| `account` | `getBalance` · `getSummary` · `listExchangeRates` |
| `webhooks` | `resendPayment` · `register` · `listDeliveries` · `requeueDelivery` · `sendLegacyTest` · `sendTestPayment` · `sendTestWallet` · `sendTestPayout` · `sendTestConversion` · `rotateSecret` · `setActive` |
| `settings` | `setAccuracy` · `getAccuracy` · `setAutoRefund` · `getAutoRefund` · `setDiscount` · `listDiscounts` · `listApiLog` · `getAutoConvert` · `setAutoConvert` · `setAcceptedCurrencies` · `listAcceptedCurrencies` · `setPayoutFeeConfig` · `getPayoutFeeConfig` · `setRefundFeeConfig` · `getRefundFeeConfig` · `setPaymentFeeConfig` · `getPaymentFeeConfig` · `setAutoWithdrawRule` · `listAutoWithdrawRules` · `deleteAutoWithdrawRule` · `configureVrcs` |
| `apiAllowlist` | `list` · `addEntry` · `removeEntry` · `setEnabled` |
| `referrals` | `getInfo` |
| `documents` | `getSigned` · `getBalance` · `getFees` · `getLedger` · `getSplit` · `getPayoutLinkCheque` · `getStatement` · `getBatch` · `getPaymentLink` · `getWalletStatement` · `getReferrals` · `createJob` · `getJob` · `downloadJobFile` |
| `checkout` | `getSourceOfFundsForm` · `submitSourceOfFunds` · `getPublicPaymentLink` · `paymentLink` · `listCurrencies` · `get` · `selectMethod` · `startOnramp` · `getOnramp` · `getQr` |
| `sandbox` | `onboardStore` · `faucet` · `simulateDeposit` · `reset` · `listWebhooks` · `replayWebhook` |
| `cliLogin` | `start` · `poll` · `logout` |
<!-- /sdkgen:methods -->

Every method takes an optional last argument, `RequestOptions` — see
[Retries, idempotency and timeouts](#retries-idempotency-and-timeouts). Path parameters come first
(`$oblodai->checkout->get($uuid)`), query parameters are named arguments
(`$oblodai->documents->getStatement(from: '2026-01-01', to: '2026-01-31', format: 'csv')`), and a
body is an array or a request model. Each method's docblock carries the gateway's own description,
its route and the error codes it can answer with.

### Lists

The paged list methods return an `Oblodai\Core\Page` — a lazy handle: nothing is requested until you
consume it. `foreach` walks every item across every page, `byPage()` every page, `first()` (or
`items()` / `paginate()`) only the first. Walking stops when the gateway says `has_pages: false` or
hands back a short page, whichever comes first.

```php
$page = $oblodai->payments->listHistory(['limit' => 50]);
$page->items();                        // list<PaymentView> — the first page
$page->paginate()->total;              // total, per_page, offset, has_pages

foreach ($oblodai->payouts->listHistory(['status' => 'confirmed']) as $payout) {
    echo $payout->uuid, "\n";          // walks page after page, lazily
}

foreach ($oblodai->payouts->listHistory(['limit' => 100])->byPage() as $onePage) {
    echo count($onePage), ' of ', $onePage->paginate->total, "\n";   // one request per page
}

$refunds = $oblodai->payouts->listHistory(['kind' => 'refund'])->all(1000);
```

### Long-running operations

Bulk batches (`batches->createPayment/createPayout/createRefund`, `payouts->createTransferBatch`)
and document exports (`documents->createJob`) finish in the background. `asJob()` wraps the create
call and returns an `Oblodai\Core\Job`: `->id`, the create answer `->result`, and `->wait()`, which
polls until the status is terminal (`completed`/`stopped` for a batch, `done`/`failed`/`expired` for
an export) and returns that last answer — a failed job is returned, not thrown.

```php
use Oblodai\Generated\Resource\Batches;
use Oblodai\Generated\Resource\Documents;

$job = $oblodai->batches->asJob(fn (Batches $b) => $b->createPayout(['payouts' => [[
    'amount' => '5', 'currency' => 'USDT', 'network' => 'tron',
    'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx', 'order_id' => 'bulk-1',
]]]));
$info = $job->wait(timeout: 300, interval: 2);   // BatchInfoResponse
echo $job->id, ' ', Status::value($info->status), "\n";

$export = $oblodai->documents->asJob(fn (Documents $d) => $d->createJob(['kind' => 'statement', 'format' => 'csv']));
$export->wait();
$export->download()->saveTo(sys_get_temp_dir() . '/statement.csv');
```

Which operations are jobs, and how each is followed, is the contract's (`x-sdk-poll`), generated
into `Oblodai\Generated\Facts` and read through `Oblodai\Lro::JOBS`.

### Statuses

- Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`
  (and `under_review`). `Status::isPaymentPaid()` is true for `paid`/`paid_over`; `wrong_amount`
  (underpaid) waits for `payments->resolve(['uuid' => …, 'action' => 'accept'|'refund'])`;
  `Status::isPaymentFinal()` covers the rest.
- Payout: `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.

Which statuses are final, and which of those mean success, is the contract's (`x-status-classes`):
the generated enums carry it (`PaymentStatus::FINAL`, `PaymentStatus::Paid->isSuccess()`), and the
`Status` helpers wrap it.

Prefer webhooks for state changes; poll `getInfo()` only as a fallback.

A status (and every other closed vocabulary) is the generated enum's case — or, for a value newer
than this SDK, the plain wire string. An unfamiliar value never throws: the gateway adds statuses on
its own schedule, and a webhook receiver that refused the first unfamiliar one would answer 500 to
an authentic delivery and have it redelivered for a day.

```php
use Oblodai\Generated\Enum\PaymentStatus;

$payment = $oblodai->payments->getInfo(['uuid' => $invoice->uuid]);
$payment->status === PaymentStatus::Paid;   // a known value is the case
Status::value($payment->status);            // "paid" — the wire string, known or not
Status::isPaymentPaid($payment->status);    // false for a status this SDK does not know
$payment->extra;                            // fields newer than this SDK, exactly as received
```

### Money

Amounts are decimal **strings** in both directions — the models type them `string`, and a float
anywhere in a request body (bar the numbers the contract types `number` — not money,
`Oblodai\Generated\Facts::NON_MONEY_NUMBERS`) fails before the network with `sdk.float_amount`. `Oblodai\Helper\Money::add()`, `subtract()`, `compare()`,
`equals()`, `isZero()`, `isPositive()`, `assertAmount()` do exact decimal arithmetic on those
strings. Never cast a money field to `float`, and never compare amounts as strings (`"9"` sorts
after `"10"` as text and before it as money — use `compare()`).

## Webhooks

Register an endpoint with `webhooks->register(['url' => …])` — the signing secret is returned once,
so store it then. Verify every delivery over the **raw** request bytes (`file_get_contents(
'php://input')`), with the request headers (`getallheaders()`); a re-serialised parse will not
match.

```php
use Oblodai\Generated\Model\PaymentWebhook;
use Oblodai\Webhook\Verifier;

$delivery = Verifier::verify(
    rawBody: $rawBody,                      // the RAW bytes, never a re-encoded parse
    headers: $headers,
    secret: (string) getenv('OBLODAI_WEBHOOK_SECRET'),
);

if ($delivery->isTest) {                    // a rehearsal delivery — no money moved
    http_response_code(200);
} else {
    $event = Verifier::model($delivery->event);   // PaymentWebhook | PayoutWebhook | WalletWebhook | ConversionWebhook | null
    if ($event instanceof PaymentWebhook && Status::isPaymentPaid($event->status)) {
        markOrderPaid($event->order_id);
    }
    http_response_code(200);
}
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
either — `$delivery->event` keeps the body, `Verifier::model()` returns null and
`Verifier::isKnownEvent()` false.

`$delivery->eventId` (`X-Webhook-Event-Id`) is the id of the STATE a delivery carries — the same for
every retry and resend of it; keep the ones you handled and skip repeats. `$delivery->id`
(`X-Webhook-Id`) only identifies one delivery. `Verifier::isStale($delivery->event, $lastSequence)`
drops out-of-order deliveries. After `webhooks->rotateSecret()` pass `previousSecret:` for at least
26 hours. An empty `secret` is a `ConfigException`, never a verification against `HMAC('', body)`;
the freshness window is 300 seconds (`toleranceSec: 0` disables it) and is checked after the MAC,
so it cannot be used to probe your clock. See [`examples/webhook-receiver.php`](examples/webhook-receiver.php).

## Errors

Every failure is an `Oblodai\Exception\OblodaiException` whose message reads in a log line —
`[payout.insufficient_funds] insufficient balance (request_id=5f0c…)` — and which carries the API's
error envelope: `errorCode`, `detail` (the text alone), `httpStatus`, `retryable`, `retryAfter`,
`requestId`, `field`, `synthetic` (the answer came from a proxy, not the API). Quote `requestId` to
support; `json_encode($err)` keeps the message and the classification and drops the raw body.

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

`retryable` is authoritative: the SDK already retried whatever it should have. Branch on
`errorCode` — `family.reason`; the codes each method can answer with are listed in its docblock, and
the full catalogue is the generated enum `Oblodai\Generated\Enum\ErrorCode`.

```php
use Oblodai\Exception\OblodaiException;

try {
    $payout = $oblodai->payouts->create([
        'amount' => '10', 'currency' => 'USDT', 'network' => 'tron',
        'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx', 'order_id' => 'payout-1002',
    ]);
} catch (OblodaiException $err) {
    match ($err->errorCode) {
        // retryable — the balance may still arrive
        'payout.insufficient_funds', 'payout.funds_maturing' => scheduleRetry($err->retryAfter ?? 60),
        default => throw $err, // the SDK already retried what was safe to retry
    };
}
```

The SDK's own codes never come from the API: `sdk.missing_credentials`, `sdk.bad_config`,
`sdk.bad_header`, `sdk.bad_path_param`, `sdk.bad_amount`, `sdk.float_amount`,
`sdk.bad_idempotency_key`, `sdk.idempotency_unsupported`, `sdk.lro_unresolved` (all
`ConfigException`), `sdk.bad_envelope` and `webhook.bad_payload` (`ContractException`),
`sdk.response_too_large`, `transport.timeout`, `transport.network`, `transport.deadline`
(`TransportException`).

## Retries, idempotency and timeouts

- **Safe to repeat** is the contract's own answer (`x-retry-safe`), not a guess from the path: each
  generated route carries it (`Oblodai\Generated\Routes`).
- An error is retried only when the API says `retryable: true`. Answers without an API envelope (a
  proxy 502/503) and transport failures are retried only on retry-safe routes or keyed writes — a
  write the gateway does not deduplicate is never re-sent once it may have arrived.
- **Idempotency keys** are generated automatically on the routes the gateway deduplicates (one per
  call, reused on every retry), so a timeout can never produce a second payout. Pass your own key to
  stay safe across restarts too. On a route the gateway does not deduplicate the SDK refuses a key
  (`sdk.idempotency_unsupported`); a key reused with a different body is a 409
  `idempotency.key_reused`.
- **Request ids:** every call sends its own `X-Request-ID` (the same on every retry of it) — pass
  `requestId` to tie your logs to ours. An error carries it when the gateway's envelope names none.
- **Per-call options:** `new RequestOptions(idempotencyKey: …, timeout: …, maxRetries: …,
  extraHeaders: […], requestId: …)`. `timeout` is seconds per attempt; `extraHeaders` merge over the
  client's, case-insensitively; nothing the SDK signs can be overridden from there.
- **Policy:** `retry: new Retry(maxRetries: 2, baseDelayMs: 250, maxDelayMs: 4000, maxRetryAfterMs:
  30000)` — the defaults. `timeout` (default 30 s) bounds one attempt, `deadline` (default 90 s) the
  whole call including pauses. `Retry-After` always wins over the computed backoff.
- **Clock skew** is corrected once per call: if the gateway rejects the timestamp, the SDK learns
  the server's time from the `Date` header and re-signs, then keeps the offset for later calls.
- **Redirects are never followed** — the signature covers the path that was requested — and the
  response body is read with a ceiling: 8 MiB on JSON routes, 64 MiB on document routes.

```php
$quick = $oblodai->payments->getInfo(
    ['uuid' => $invoice->uuid],
    new RequestOptions(timeout: 5, maxRetries: 0, requestId: 'checkout-7-status'),
);
```

## Raw responses, client copies and hooks

```php
use Oblodai\Core\Hooks;
use Oblodai\Core\ResponseInfo;
use Oblodai\Generated\Resource\Payments;

// Status, headers and request id of a successful answer; parse() is what the method returns.
$raw = $oblodai->payments->withRawResponse(fn (Payments $p) => $p->getInfo(['uuid' => $invoice->uuid]));
echo $raw->status, ' ', $raw->requestId, ' ', $raw->parse()->uuid, "\n";

// A copy with other defaults; the original is untouched, the HTTP client and clock are shared.
$patient = $oblodai->withOptions(timeout: 60, maxRetries: 5, extraHeaders: ['X-Shop' => 'eu-1']);

// Hooks see every attempt (the signature redacted) — for metrics, tracing, structured logs.
$observed = new Oblodai(hooks: new Hooks(
    onResponse: function (ResponseInfo $r): void {
        error_log(sprintf('%s %d %.3fs %s', $r->request->operationId, $r->status, $r->elapsed, $r->request->requestId));
    },
));
```

An error status still throws through `withRawResponse()`. A hook that throws stops the call.

## Configuration

```php
use Oblodai\Core\Retry;

$oblodai = new Oblodai(
    publicId: $publicId,
    secret: $secret,
    baseUrl: 'https://api.oblodai.com',
    timeout: 30,
    deadline: 90,
    retry: new Retry(maxRetries: 2),
);
```

| option                 | default                     | what it does                                                     |
| ---------------------- | --------------------------- | ---------------------------------------------------------------- |
| `publicId` / `secret`  | environment                 | the API key; the secret only ever signs                          |
| `baseUrl`              | `https://api.oblodai.com`   | API origin; a path prefix is kept                                |
| `http`                 | `CurlHttpClient`            | custom HTTP stack — see `Psr18HttpClient`                        |
| `timeout`              | `30`                        | per-attempt timeout, seconds                                     |
| `deadline`             | `90`                        | overall budget per call, retries and pauses included, seconds    |
| `retry`                | `new Retry()`               | retry policy; `new Retry(maxRetries: 0)` turns retries off       |
| `logger`               | none                        | structured logger; `OBLODAI_LOG` picks a console one             |
| `headers`              | `[]`                        | extra headers on every request                                   |
| `hooks`                | none                        | `new Hooks(onRequest: …, onResponse: …)`                         |
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
show `[redacted]`. The models that carry a one-time secret (a webhook `secret`, a payout link's
`claim_token`, `claim_url` and `passcode`) mask it in `json_encode`, `var_dump` and `serialize`
while the property stays readable and `toArray()` returns it as is — but `print_r`/`var_export`
read public properties directly, so do not `print_r` such a model. Whatever logger you inject is
wrapped, so redaction happens before the SDK hands anything over.

### HTTP stack

cURL is the default. To reuse your own client, wrap any PSR-18 implementation:

```php
use Oblodai\Http\Psr18HttpClient;

$oblodai = new Oblodai(
    publicId: $publicId,
    secret: $secret,
    http: new Psr18HttpClient($client, $requestFactory, $streamFactory),
);
```

PSR-18 describes only "send this request, get a response", so three things must be configured on the
client itself: **no redirects** (`allow_redirects: false` in Guzzle, `max_redirects: 0` in
Symfony), **timeouts** (connect and total — the SDK's `timeout` cannot be applied through PSR-18,
only the overall per-call deadline) and **TLS verification left on**. `CurlHttpClient` enforces all
three itself.

### Self-hosted or local gateway

`baseUrl: 'http://localhost:8093'` works out of the box; any other plain-http host needs
`allowInsecureBaseUrl: true` (or `OBLODAI_ALLOW_INSECURE=1`). A path prefix in `baseUrl` is kept.
The provisioning route `sandbox->onboardStore($merchantId)` needs the gateway's `adminToken:` (or
`OBLODAI_ADMIN_TOKEN`), which is sent as `X-Admin-Token` on that route only.

## Generated code

`src/Generated` (`Oblodai\Generated\…` — the resources, the models, the enums, the route table and
`Facts`: long-running operations, webhook kinds, non-money numbers)
is generated by the gateway's `tools/sdkgen` from its OpenAPI contract and is never edited by hand;
the hand-written runtime (`Oblodai\Core`, `Oblodai\Exception`, `Oblodai\Webhook`, `Oblodai\Http`) is
what signs, sends, retries and parses. `names.lock` pins every public method name: a name that
vanishes or changes fails the generator as a breaking change, and a new one is added to the lock
by the generator, as is the method table above. `make drift` regenerates into a temporary directory
and fails when `src/Generated`, `names.lock` or that table differs.

## Development

```bash
make ci                                          # vendor, drift, lint, stan (level max), test, package
make ci OBLODAI_BACKEND=/path/to/oblodai-backend # the backend checkout (default ../oblodai-backend)
make live OBLODAI_LIVE_URL=http://127.0.0.1:8095 # the live tier against a running gateway
```

Everything runs in docker (`php:8.3-cli`, php-cs-fixer on `php:8.2-cli`, `composer:2`); the drift
check needs Go for the backend's generator. The tests are unit (runtime), contract (generated code, README, examples) and the shared
conformance suite of every Oblodai SDK (`tools/sdkgen/conformance` of the backend: signing vectors,
retries, idempotency keys, money, forward compatibility). The live tier is skipped unless
`OBLODAI_LIVE_URL` points at a running gateway; it provisions its own merchant and spends only fake
money.

Writing code with an AI agent? Point it at [AGENTS.md](AGENTS.md). Upgrading from 1.x — see
[MIGRATION-2.0.md](MIGRATION-2.0.md); the release history is in [CHANGELOG.md](CHANGELOG.md).

## License


MIT — see [LICENSE](LICENSE).
