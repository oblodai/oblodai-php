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

Resources are properties, not methods, and the payout key is a first-class option:

```php
$client->payments()->create($params);                   // 1.2
$oblodai->payments->create($params);                    // 1.3

new Oblodai\Oblodai(publicId: $pk, secret: $sk, payoutPublicId: $wk, payoutSecret: $ws);
```

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
echo $payment->status->value;         // enum-backed where the vocabulary is closed
$raw = $payment->toArray();           // the untouched wire body, if you need it
```

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
sending a header the gateway ignores.

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

## Transport

`Oblodai\Http\Transport` (1.2) became `Oblodai\Http\HttpClient`, with `CurlHttpClient` as the
default and `Psr18HttpClient` for any PSR-18 client:

```php
new Oblodai\Oblodai(http: new Oblodai\Http\Psr18HttpClient($client, $requestFactory, $streamFactory));
```

## Requirements

PHP ≥ 8.1 (1.2 supported 8.0). `psr/http-client`, `psr/http-factory` and `psr/http-message` are
now dependencies — interface packages only, no implementation is pulled in.
