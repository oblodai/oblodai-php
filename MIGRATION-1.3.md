# Migrating from 1.2 to 1.3

1.3 is a rewrite generated from the gateway's own contract snapshot. The reason is not tidiness:
**1.2 signs requests with a four-field recipe and the current gateway answers 401 to every call.**
There is no configuration that makes 1.2 work again.

The shape of the code changes with it: responses are typed objects instead of arrays, and the
client is constructed with named arguments.

## The client

```php
// 1.2
$client = new Oblodai\Client($publicId, $secret);      // or Oblodai\Client::fromEnv()
$client = new Oblodai\Client($publicId, $secret, 'https://api.oblodai.com');

// 1.3
$oblodai = new Oblodai\Oblodai(publicId: $publicId, secret: $secret);
$oblodai = new Oblodai\Oblodai();                       // same env vars, read automatically
$oblodai = new Oblodai\Oblodai(baseUrl: 'https://api.oblodai.com/');
```

Resources are properties, not methods:

```php
$client->payments()->create($params);                   // 1.2
$oblodai->payments->create($params);                    // 1.3
```

One API key signs everything. 1.3 takes `publicId` + `secret` and nothing else: the separate payout
credential pair (`payoutPublicId`/`payoutSecret`, `OBLODAI_PAYOUT_*`) and the per-call
`preferPayoutKey` option are gone, because the gateway issues one key per merchant and it opens
money in, money out, settings and documents alike. If you still hold a legacy `oblodai_pk_…` /
`oblodai_wk_…` pair, build one client per key.

`account()`, `rates()` and `webhooks()` were split into the namespaces the API actually has:

| 1.2                                    | 1.3                                        |
| -------------------------------------- | ------------------------------------------ |
| `$client->account()->balance()`        | `$oblodai->account->balance()`             |
| `$client->account()->transferToUser()` | `$oblodai->transfers->toUser()`            |
| `$client->rates()->list()`             | `$oblodai->catalog->exchangeRates()`       |
| `$client->rates()->currencies()`       | `$oblodai->catalog->currencies()`          |
| `$client->webhooks()->deliveries()`    | `$oblodai->webhooks->deliveries()` (a page)|
| `$client->sandbox()->simulateDeposit()`| `$oblodai->sandbox->deposit()`             |
| `$client->sandbox()->listWebhooks()`   | `$oblodai->sandbox->webhooks()`            |
| `$client->sandbox()->replayWebhook()`  | `$oblodai->sandbox->replay()`              |
| `$client->links()`                     | `$oblodai->paymentLinks`                   |

## Responses

Every response body is a readonly object now, with the wire's own field names:

```php
$payment = $client->payments()->create($params);
echo $payment['url'];                 // 1.2 — array
echo $payment->url;                   // 1.3 — Oblodai\Contract\Model\Payment
echo $payment->status->value;         // always the raw wire string
$raw = $payment->toArray();           // the untouched wire body, if you need it
```

### Field renames

The 1.2 arrays used names the gateway does not: 1.3 uses the wire's own, everywhere.

| 1.2 array key                | 1.3 property                                    |
| ---------------------------- | ----------------------------------------------- |
| `$payment['payment_status']` | `$payment->status` (an `OpenEnum`, see below)   |
| `$payment['payer_amount']`   | `$payment->payer_amount`                        |
| `$payment['amount_paid']`    | `$payment->amount_paid`                         |
| `$payment['is_final']`       | `$payment->is_final`                            |
| `$payout['payout_status']`   | `$payout->status`                               |
| `$link['claim_url']`         | `$link->claim_url` (on `PayoutLink`)            |
| `$res['deliveries']`         | `$oblodai->webhooks->deliveries()` — a `Page`   |
| `$batch['batch_id']`         | `$batch->batch_id` (on `BatchSubmitted`)        |
| `$res['items']`              | `payouts->mass()` / `payoutLinks->batch()` return the elements directly |
| `['idempotency_key' => …]`   | `new RequestOptions(idempotencyKey: …)`         |
| `['expires_in_hours' => …]`  | `expires_in_seconds` on `PayoutLinkRequest`     |

### Closed vocabularies are open values

`$payment->status` is not a PHP enum: it is an `Oblodai\Contract\Model\OpenEnum` that always carries
the raw string and carries the typed case only when this snapshot knows it.

```php
$payment->status->value;                      // "paid" — the wire string, always
$payment->status->is(PaymentStatus::Paid);    // compare against a case or a string
$payment->status->known === PaymentStatus::Paid;
$payment->status->isKnown();                  // false when the gateway is newer than the SDK
```

An unknown value never throws. Earlier 1.3 pre-releases raised `ContractException` from the model
constructor; in a webhook receiver that meant answering 500 to an authentic delivery and having it
redelivered for a day. If you want drift to be loud in your test suite, call
`Oblodai\Contract\Model\Wire::strict()` there — never in production.

Lists are lazy pages instead of arrays:

```php
$page = $oblodai->payments->history(['limit' => 50]);
$page->items();                       // list<Payment> — one page
$page->paginate()->has_pages;
foreach ($oblodai->payments->history() as $payment) { /* every page, fetched as needed */ }
```

## Idempotency

The key no longer travels inside the request body:

```php
$client->payments()->create($params + ['idempotency_key' => 'k']);                     // 1.2
$oblodai->payments->create($params, new RequestOptions(idempotencyKey: 'k'));          // 1.3
```

Keys are still generated automatically on create routes. Passing one to a route the gateway does
not deduplicate now throws `ConfigException` (`sdk.idempotency_unsupported`) instead of silently
sending a header the gateway ignores — including on list methods, where it used to be dropped
without a word. A key the SDK itself rejects (empty, over 255 characters, whitespace, control
characters) is a `ConfigException` (`sdk.bad_idempotency_key`), not a `ValidationException`: nothing
was sent, so there is no 400 to report.

Which routes may be re-sent after a transport failure is no longer inferred from the path. The
gateway hand-classifies every route and ships the verdict as `safe` in the contract snapshot;
`Routes::SPECS` exposes it, and the code generator refuses a contract that omits it.

## Errors

```php
// 1.2
catch (Oblodai\Exception\ApiException $e) { $e->getCode(); }

// 1.3
catch (Oblodai\Exception\OblodaiException $e) {
    $e->errorCode;    // "payout.insufficient_funds"
    $e->httpStatus; $e->retryable; $e->retryAfter; $e->requestId; $e->field; $e->synthetic;
}
```

`ConnectionException` became `TransportException`; `ApiException` is now the base of the
status-specific subclasses (`ValidationException`, `AuthenticationException`, `PermissionException`,
`NotFoundException`, `ConflictException`, `IdempotencyConflictException`, `RateLimitException`,
`UnavailableException`, `InternalException`).

Failures the SDK raises itself carry `sdk.` codes and never claim the API answered:
`sdk.missing_credentials`, `sdk.bad_config`, `sdk.bad_header`, `sdk.bad_path_param`,
`sdk.bad_amount`, `sdk.bad_idempotency_key`, `sdk.idempotency_unsupported` (`ConfigException`);
`sdk.bad_envelope` and `webhook.bad_payload` (`ContractException`); `sdk.response_too_large`
(`TransportException`). `Money` no longer throws `InvalidArgumentException` — a malformed amount is
`ConfigException` with `sdk.bad_amount`, so `catch (OblodaiException)` covers it.

`$err->retryAfter` reports what the gateway asked for, clamped to 24 hours. The SDK's own sleep is
still capped by `Retry::$maxRetryAfterMs` (30 s by default).

## Webhooks

```php
// 1.2
Oblodai\Webhooks::verify($secret, $rawBody, $timestamp, $signature);
Oblodai\Webhooks::constructEvent($secret, $rawBody, $timestamp, $signature);

// 1.3
$delivery = Oblodai\Webhook\Verifier::verify($rawBody, $headers, $secret, previousSecret: $old);
$event = $delivery->event;            // PaymentEvent | PayoutEvent | WalletEvent
```

Verification still happens over the raw bytes, and still tolerates ±300 s of skew; the rotation
overlap (`X-Webhook-Signature-Prev`) is now handled for you.

Three things changed for receivers:

- **An empty secret is refused.** 1.2 verified with whatever it was given, and `HMAC('', body)` is
  computable by anyone — `getenv('OBLODAI_WEBHOOK_SECRET')` returning `false` was enough to accept
  forgeries. It is now a `ConfigException` before any crypto, as are an empty `previousSecret` and a
  negative tolerance.
- **A verified body that will not parse is `WebhookPayloadException`** (`webhook.bad_payload`, a
  `ContractException`), not a signature failure. A receiver that answers 401 on
  `SignatureException` must answer 2xx here: the MAC proved the event is ours, and a 401 would make
  the gateway redeliver it for a day.
- **An unmodelled event `type` does not throw.** It decodes to `UnknownEvent` with the raw body
  intact; `Verifier::isKnownEvent($event)` tells you which kind you have, and `isTestEvent()` /
  `isStale()` still work on it. `sequence()` is `?int` now, and an event without one is never
  reported as stale.

Rehearsal deliveries (`webhooks->test()`, sandbox) carry `test: true` in the signed body and
`X-Webhook-Test: true`; `$delivery->isTest` and `Verifier::isTestEvent($event)` are true for them.
They are signed exactly like live ones — branch on the flag and never treat one as money.

## Transport

`Oblodai\Http\Transport` (1.2) became `Oblodai\Http\HttpClient`, with `CurlHttpClient` as the
default and `Psr18HttpClient` for any PSR-18 client:

```php
new Oblodai\Oblodai(http: new Oblodai\Http\Psr18HttpClient($client, $requestFactory, $streamFactory));
```

PSR-18 cannot express "no redirects", "time out after N ms" or "verify TLS", so configure all three
on the injected client — `CurlHttpClient` enforces them itself and cannot be talked out of them
through `$curlOptions`. Both clients read the response body with a ceiling (8 MiB on JSON routes,
64 MiB on document routes) and refuse anything larger with `sdk.response_too_large`.

## Provisioning and the admin token

`merchants->create()` and `merchants->createSandbox($id)` provision a merchant on a self-hosted
gateway. They sign nothing and carry the gateway's admin token:

```php
$ob = new Oblodai\Oblodai(baseUrl: 'https://gw.internal', adminToken: getenv('OBLODAI_ADMIN_TOKEN'));
$merchant = $ob->merchants->create(['email' => 'owner@shop.example']);
$merchant->api_key->public_id;       // the secret is shown once — store it now
```

`X-Admin-Token` rides on those routes and nowhere else, and a caller-supplied header of that name is
dropped.

## Secrets in logs

Credentials and the admin token no longer live on the object, so `print_r`, `var_export`,
`var_dump`, `json_encode` and `serialize` of the client, its config or its transport show
`[redacted]`. `Credentials::$secret` became `Credentials::secret()` for the same reason, and
`Config::$adminToken` is a `Core\Secret` (`->reveal()` for the bytes).

The models that carry a one-time secret — `WebhookEndpoint`, `WebhookSecretRotated`, `ApiKeyPair`,
`MerchantOnboarded`, `PayoutLink` (`claim_token`, `claim_url` — it embeds the token — and
`passcode`) and `BatchElement` — mask it in every wholesale rendering, while the property itself
stays readable. Whatever logger you inject is wrapped
so redaction happens before the SDK hands anything over. PHP cannot intercept `print_r` on an object
with public properties, so do not `print_r` a model that holds a secret.

## Wallet blocking

New in 1.3: `wallets->block()` stops crediting a static address, and
`wallets->refundBlockedDeposit()` sends back what landed on it afterwards. Deposits on a blocked
address are held for a refund decision instead of being credited.

## Requirements

PHP ≥ 8.1 (1.2 supported 8.0). `psr/http-client`, `psr/http-factory` and `psr/http-message` are
now dependencies — interface packages only, no implementation is pulled in.
