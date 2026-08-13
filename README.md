# Oblodai PHP SDK

> [Читать по-русски →](README.ru.md)

The official PHP SDK for the **Oblodai** payment gateway: accepting payments, payouts, static wallets,
webhooks. One API key — all functionality. No external dependencies (cURL), with an injectable transport.

## Installation

```bash
composer require oblodai/sdk
```

Requirements: PHP 8.0+, the `json` and `curl` extensions.

## Where to get keys

Keys are issued **in the Oblodai dashboard** — <https://oblodai.com> — in the API keys section.
A pair of two values:

- **public id** — the project identifier, sent in the `X-Public-Id` header;
- **secret** — used to sign every request (`X-Signature`). The secret **is shown once,
  at the moment the key is created**: save it right away, there is nowhere to look it up later — you
  can only issue a new one.

For the sandbox, a **test** key is issued in the same section: its public id starts with `test_…`,
the secret with `oblodai_test_…`. Test and live keys are interchangeable as far as your code is
concerned: see [Sandbox](#sandbox--testing-v120) below — start there.

Keep the keys in environment variables (see `.env.example`) — the secret **server-side only**, never
in the browser:

```bash
export OBLODAI_PUBLIC_ID=test_...          # live: oblodai_...
export OBLODAI_SECRET=oblodai_test_...     # live: oblodai_live_...
# optional: export OBLODAI_BASE_URL=https://api.oblodai.com
```

> `base_url` must be **https**: the signature travels in a header, and over plain http any
> intermediary can see it. The client rejects a non-https address with a `ConfigException` already
> at construction time. The only exception is local setups on loopback (`http://localhost:8095`,
> `http://127.0.0.1:…`, `http://[::1]:…`).

```php
use Oblodai\Client;

$client = Client::fromEnv(); // OBLODAI_PUBLIC_ID / OBLODAI_SECRET / OBLODAI_BASE_URL
```

## Quick start

```php
use Oblodai\Client;

// or explicitly (equivalent to fromEnv above):
$client = new Client($publicId, $secret, ['base_url' => 'https://api.oblodai.com']);

$payment = $client->payments()->create([
    'amount'      => '10',
    'currency'    => 'USD',
    'order_id'    => 'order-1',
    'to_currency' => 'USDT',
    'network'     => 'tron',
    'url_callback' => 'https://your-shop.example/oblodai/webhook',
]);

echo $payment['address']; // address to pay to
echo $payment['url'];     // hosted payment page
```

The same code works with a live key — **only the key** changes, neither `base_url` nor the SDK
methods need touching. So start with the sandbox (next section) and plug in live keys once the
scenario is already proven.

## Sandbox / testing (v1.2.0)

Oblodai has a developer sandbox. **Business endpoints and integration code are identical for test
and live** — only the key changes: a test public id starts with `test_…`, a test secret with
`oblodai_test_…`. Nothing needs reconfiguring: same `base_url`, same SDK methods.

What's new — five sandbox helpers under `$client->sandbox()` that stand in for "the customer paid
on-chain". They exist **only in the sandbox**: calling them with a live key returns
403 `sandbox.live_key`. This is **TEST-ONLY code** — do not call it from production
(handy guard: `Client::isTestKey($publicId)`).

```php
use Oblodai\Client;

$client = Client::fromEnv(); // OBLODAI_PUBLIC_ID=test_..., OBLODAI_SECRET=oblodai_test_...

// 1. A regular business call — the same code as in production.
$payment = $client->payments()->create([
    'amount' => '10', 'currency' => 'USD', 'order_id' => 'order-1',
    'to_currency' => 'USDT', 'network' => 'tron',
]);

// 2. "Pay" the invoice: without amount, exactly the invoice amount is paid.
$client->sandbox()->simulateDeposit($payment['uuid']);

// 3. Wait for the status, just like in production (or receive the webhook).
$info = $client->payments()->info($payment['uuid']); // payment_status: paid

// 4. Balance "out of thin air" — and any paid call, e.g. a payout.
$client->sandbox()->faucet('USDT', '100'); // at most 1000000 per call
$client->payouts()->create(['amount' => '5', 'currency' => 'USDT', 'address' => 'T...', 'order_id' => 'w-1']);

// Also: the webhook delivery log (up to 50, newest first) and re-delivery.
$deliveries = $client->sandbox()->listWebhooks();
$client->sandbox()->replayWebhook($deliveries[0]['id']);

// Reset: cancel invoices not yet being paid and zero out balances (see the caveat below).
$client->sandbox()->reset();
```

`simulateDeposit` scenarios:

- `['amount' => '5']` — underpayment, `['amount' => '15']` — overpayment (without amount — exactly the invoice amount);
- `['confirmations' => 1, 'txid' => 't1']` — a "stuck" deposit with a low confirmation count;
  repeating with the **same** `txid` and a larger `confirmations` deepens it (same `txid` = idempotency).

Fine print:

- **an invoice short on confirmations does NOT mature on its own.** Nobody re-emits a simulated
  deposit and no cursor advances for it: the invoice will stay in `confirm_check` indefinitely.
  The only way to bring it to `paid` is to repeat `simulateDeposit` with the **same** `txid` and
  a larger `confirmations`;
- **the ~10 minutes is about something else.** That refers to the maturity **hold on payouts** (error
  `payout.funds_maturing`): freshly arrived funds cannot be withdrawn immediately. In the sandbox this
  hold is lifted by age via a background job — by default after 10 minutes
  (`GATEWAY_SANDBOX_MATURITY_MINUTES` on the gateway side). It has nothing to do with the invoice's
  confirmation depth: the hold only touches the balance. To skip the 10-minute wait — same trick:
  repeating `simulateDeposit` with the same `txid` and a sufficiently larger `confirmations` brings
  the deposit to reorg-safe depth and lifts the hold immediately;
- in UTXO networks (Bitcoin and the like) there is no auto-refund of overpayment and no payer
  address — same as in production (for a refund the address is specified explicitly).

### `reset()` is not a "clean slate"

`sandbox()->reset()` cancels invoices **only** in the `created` (in the API — `check`) and `select`
statuses, i.e. those for which no deposit has been seen yet, and zeroes out balances. An invoice with
a deposit already visible (`confirm_check`, `wrong_amount_waiting`) **stays alive on purpose**:
cancelling it would let that deposit confirm into a cancelled invoice and credit money without an
event. The sandbox does not bypass this rule — to the pipeline, a simulated deposit is
indistinguishable from a real one.

Nothing is deleted in the process: the ledger is append-only, zeroing a balance is a compensating
entry, so the history of your experiments remains readable ("why is my balance 3?" — answerable).
If you need a truly clean account — create a new one rather than expecting `reset()` to remove
one stuck in `confirm_check`.

## Verifying webhooks

Oblodai signs each delivery with a **separate endpoint secret**, returned by
`POST /v1/webhooks` — `$client->webhooks()->register($url)['secret']`. It is **not** the API key
secret: plug the API key into the signature check and you will reject 100% of webhooks. Register
the endpoint once and store the secret.

> ⚠ **Registration is an upsert of the project's single endpoint, not "add another one".**
> In the core: `INSERT ... ON CONFLICT (project_id) DO UPDATE SET url = EXCLUDED.url`. A repeated
> `register()` with a **different** URL does not create a second endpoint — it **redirects**
> deliveries: the same `endpoint_id` comes back, and the old URL silently goes quiet. The classic
> outage — running a staging-URL registration from a local script and losing production webhooks.
> The secret is **preserved** on re-registration (deliveries already queued are signed with it),
> so the response returns the same `secret`. Rotating the secret is a separate, deliberate action.

Verify incoming webhooks with that secret:

```php
use Oblodai\Webhooks;
use Oblodai\Exception\SignatureException;

$raw = file_get_contents('php://input');

// Test webhooks (is_test) are unsigned — just acknowledge them.
if (Webhooks::isTest($raw)) { http_response_code(200); exit; }

try {
    $event = Webhooks::constructEvent(
        $webhookSecret,                          // from $client->webhooks()->register($url)['secret']
        $raw,
        $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '',
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''
    );
    // $event['uuid'], $event['status'], ...
    // Re-check the status authoritatively:
    $info = $client->payments()->info($event['uuid']);
    // $info['payment_status'] — see the status table below; $info['is_final'] — terminality.
} catch (SignatureException $e) {
    http_response_code(403);
}
```

## Payment statuses

`payment_status` in the responses of `payments()->info()`, `publicGet()` and in webhooks:

| Status | Meaning | Terminal |
| --- | --- | --- |
| `check` | invoice created, no payment seen yet | no |
| `confirm_check` | payment seen, waiting for network confirmations | no |
| `wrong_amount_waiting` | a **partial** payment is visible, waiting for the remainder | **no** |
| `wrong_amount` | invoice closed underpaid | yes |
| `paid` | paid in full | yes |
| `paid_over` | overpaid (the excess goes to auto-refund, if it is enabled and the network supports it) | yes |
| `cancel` | expired or cancelled | yes |
| `select` | currency-agnostic invoice, the customer has not chosen a currency yet | no |

Do not enumerate terminal statuses by hand — the response carries the **`is_final`** flag.

**`wrong_amount_waiting` ≠ `wrong_amount`.** The former is "less money arrived, but the invoice is
still alive, the customer can pay the rest". The latter is "the invoice closed underpaid, decide its
fate". Hence the rule for `payments()->resolve()`: it accepts **only** `wrong_amount`, and answers
`wrong_amount_waiting` with `409 resolution.not_underpaid`. That `409` is not a transient failure
(the SDK does not retry it either): wait for `wrong_amount` and call resolve then.

Payout statuses (`payouts()->info()`): `check` (awaiting approval) → `process` (approved, on its way
or sent) → `paid` (confirmed); plus `fail` and `cancel`.

## Resources

- `payments()` — create, info, history, services, qr, refund, accepted, accuracy, autorefund, discount · **since v1.1.0:** createBatch, refundBatch, sendEmail, resolve · **since v1.2.0:** public publicGet/publicSelect (unsigned, for your own checkout pages)
- `payouts()` — create, createMass, info, history, services, calculate, approve, fee-config, refund · **since v1.1.0:** createBatch
- `batches()` **(v1.1.0)** — info (batch progress and results)
- `paymentLinks()` **(v1.1.0)** — payment links: create, list, info, toggle, publicGet, checkout.
  The canon across all SDKs is `payment_links` (in each language's idiom: `paymentLinks()` in PHP/JS,
  `payment_links` in Python/Rust, `PaymentLinks()` in Go), so code ports between languages without
  renames. The short `links()` is a documented alias; both names are equivalent and
  supported
- `payoutLinks()` **(v1.1.0)** — "crypto checks": create, createBatch (up to 500), list, info, cancel + public claimInfo/claim (unsigned)
- `splits()` **(v1.1.0)** — splitToAddress, splitToMerchant, createRule, listRules, deleteRule, getConfig, setConfig
- `wallets()` — create, block, blockedAddressRefund, qr
- `account()` — balance, referral, transferToPersonal, vrcs · **since v1.2.0:** transferToUser, transferBatch (transfers to platform users)
- `webhooks()` — register (⚠ upsert of the project's single endpoint), deliveries (**since v1.2.0** returns a list, not an envelope), testPayment/Wallet/Payout
- `settings()` — auto-withdraw, IP allowlist
- `rates()` — list (exchange rates), currencies (catalog, public)
- `sandbox()` **(v1.2.0)** — test keys only: simulateDeposit, faucet, reset, listWebhooks, replayWebhook

## New in v1.1.0 (in brief)

```php
// Batches: up to 5000 payments/refunds/payouts in one signed request.
$sub  = $client->payments()->createBatch([
    ['amount' => '10', 'currency' => 'USD', 'order_id' => 'a-1'],
    ['amount' => '20', 'currency' => 'EUR', 'order_id' => 'a-2'],
]); // 'continue' (default) or 'stop' as the second argument
$info = $client->batches()->info($sub['batch_id'], 100, 0);

// Payment link: many people pay, each payment is its own invoice.
$link = $client->paymentLinks()->create(['amount_mode' => 'open', 'currency' => 'USD']);
// $client->links() — the same resource under the short alias.

// Split: a share of every payment automatically goes to a partner.
$client->splits()->splitToAddress('T...', 'tron', 10.0, 'partner A');

// Invoice by e-mail (a message with a "Pay" button).
$client->payments()->sendEmail($payment['uuid'], null, 'buyer@example.com');

// The fate of an underpayment: keep it or return it to the payer.
$client->payments()->resolve(['uuid' => $payment['uuid'], 'action' => 'accept']);

// Payout link ("crypto check"): a payout without knowing the recipient's wallet.
// Set expires_in_hours EXPLICITLY: without it the backend clamps the lifetime to 1 hour.
$check = $client->payoutLinks()->create([
    'currency' => 'USDT', 'network' => 'tron', 'amount' => '25',
    'reference' => 'bonus-42', 'expires_in_hours' => 168,
]);
// Hand $check['claim_url'] to the recipient; claim_token is visible ONLY in the create response.
// Locally claim_url may come back EMPTY — see "The gateway assembles the links" below.

// The recipient (public, no API key):
$client->payoutLinks()->claimInfo($check['claim_token']);          // check details
$client->payoutLinks()->claim($check['claim_token'], 'T-address'); // claim to their own wallet
```

### The gateway assembles the links (and locally they can be empty)

`payment['url']`, `link['url']` and `check['claim_url']` are not data from the database but strings
the gateway glues together from its **public base URL** (`GATEWAY_PUBLIC_BASE_URL`). If it is not
set, the field arrives as an **empty string**. In production this is impossible — the gateway simply
will not start without that setting — but on a local setup it happens all the time, and it is
**neither an SDK bug nor a core bug**.

The identifiers are always there regardless: a payment's `uuid`, a payment link's `link_id`, a
payout link's `claim_token`. When testing locally, assemble the link yourself:

```php
$base = 'http://localhost:3000';
$payUrl   = $payment['url']      ?: $base . '/pay/'   . $payment['uuid'];
$claimUrl = $check['claim_url']  ?: $base . '/claim/' . $check['claim_token'];
```

## New in v1.2.0 (in brief)

```php
// Transfer to a platform user: internal, with NO fee, from the merchant balance
// to the personal wallet of an Oblodai user. to_user_id is the user's UUID (NOT a username).
// Payout key; sent with an Idempotency-Key header.
$res = $client->account()->transferToUser([
    'to_user_id' => 'a0b1c2d3-...-000000000001',
    'amount'     => '10',
    'currency'   => 'USDT',
    'order_id'   => 'tr-1', // optional
]);
// $res: {currency, amount, to_user_id, recipient_balance}

// A batch of transfers to users (background): elements are transferToUser() bodies.
$batch = $client->account()->transferBatch([
    ['to_user_id' => 'u1', 'amount' => '5', 'currency' => 'USDT', 'order_id' => 't-1'],
    ['to_user_id' => 'u2', 'amount' => '7', 'currency' => 'USDT', 'order_id' => 't-2'],
]); // 'continue' (default) or 'stop' as the second argument
$info = $client->batches()->info($batch['batch_id']);

// Public invoice endpoints — for your OWN checkout pages, no API key
// on the frontend (the same mechanism as publicGet/claimInfo on links).
$state = $client->payments()->publicGet($payment['uuid']);        // GET /v1/pay/{id}
$final = $client->payments()->publicSelect($payment['uuid'], 'USDT', 'tron'); // POST /v1/pay/{id}/select
// publicSelect finalizes a deferred (currency-agnostic) invoice: the customer
// picks a currency/network, and the response is a regular payment result (address, payment_status, ...).
```

## Error handling

```php
use Oblodai\Exception\ApiException;

try {
    $client->payouts()->create([...]);
} catch (ApiException $e) {
    $e->getErrorCode();   // "payout.insufficient_funds" — branch on the code
    $e->getStatusCode();  // HTTP status
    $e->isRetriable();    // whether the error is transient
}
```

All SDK errors inherit from `Oblodai\Exception\OblodaiException`: `ApiException` (gateway response),
`ConnectionException` (network), `ConfigException` (configuration — e.g. a non-https `base_url`),
`SignatureException` (webhook verification). Catch the base class if you need to "catch everything".

Nothing else "slips past" that `catch`: resource methods are declared `: array` and return an array
for **any** gateway response. An empty envelope result (`{"state":0,"result":null}`) and an empty
body are `[]`, not a `TypeError` (which inherits from `Error`, not `Exception`, and would not be
caught by a normal `catch`). Fixed in v1.2.0.

The client automatically retries 5xx/429/network failures with exponential backoff (with random
jitter) and respect for the `Retry-After` header. To disable: `new Client($id, $secret, ['retry' => false])`.

**Both** forms of `Retry-After` from RFC 7231 are understood — both `60` (what the gateway itself
sends) and an HTTP-date `Wed, 21 Oct 2026 07:28:00 GMT` (what a proxy or load balancer in front of
it may send; before v1.2.0 this form was silently ignored, and the SDK retried earlier than asked).
The server's hint is honored above the SDK's own `max_delay` but is clamped to an absolute ceiling
of **300 seconds**; a date in the past means "go ahead immediately". Besides the overall timeout,
the transport sets a connection-establishment timeout — a third of the total — so a dead host
cannot eat the whole request budget on the TCP handshake.

## Idempotency (changed in v1.1.0)

Creating calls (`payments()->create/refund/resolve`, all `createBatch`/`refundBatch`,
`payouts()->create/createMass`, `payoutLinks()->create/createBatch`,
`wallets()->blockedAddressRefund`, `account()->transferToPersonal/transferToUser/transferBatch`) are sent with the
**`Idempotency-Key`** HTTP header (UUID v4). The key is generated **once before the retry loop** and
is identical across all internal retries, so a timeout/dropped connection does not create a
duplicate. The header is not part of the signature or the request body.

- **Breaking change:** the SDK no longer **auto-fills** `order_id` — it goes out as is. If you
  relied on the generated `idem-…`, set `order_id` explicitly.
- Your own key: pass `'idempotency_key' => '...'` in the parameters of a creating call (or as the
  last argument to `createBatch`) — it will go into the header.
- `payoutLinks()->create/createBatch` **(since v1.2.0)** also send the header: they reserve balance,
  and previously an auto-retry after a lost response could fund a **second** link.
  A repeat with the same key replays **the first response in full** — the same link and the same
  `claim_token` — and answers with the `Idempotent-Replayed: true` header; the balance is debited
  exactly once. **Without** the header the behavior is as before: two identical calls create
  **two** links.
- A second, independent line of defense for payout links is the per-link `reference` (unique within
  a merchant). It works without the header too, and where response replay is unavailable (see about
  batches below). A duplicate `reference` is a `409 payoutlink.duplicate_reference` (it used to be a
  `500`, i.e. the SDK retried it; now it is a terminal error and there will be no retry).
- **Batches:** a partially failed batch is replayed **as is** — failed elements are not reprocessed
  under the same key; nudge them through with a new call under a **new** key. And separately: a
  batch response larger than **256 KB is not cached**, so a repeat with the same key executes anew.
  On batches, therefore, always set a per-item `reference` — it is the only line of defense that
  remains in that case.
- `wallets()->blockedAddressRefund` **(since v1.2.0)** also sends the header, but the endpoint is
  **deliberately not wrapped** in server-side idempotency, and that is not a gap: its protection is
  server-side, unconditional and independent of the header. The backend derives a stable `reference`
  from the wallet id, takes a per-wallet advisory lock and, inside the lock, first looks for an
  already existing payout — a repeat (including a concurrent one, including one with no header at
  all) returns **the first** payout rather than creating a second one. Caveat: the address is not
  part of the `reference`, so a repeat with a **different** address returns the first payout to the
  **first** address.
- `payouts()->approve` does **not** need idempotency: it is a state transition, the server accepts
  only a payout in the `pending` status and otherwise answers `409 payout.not_pending`. A repeated
  approve physically cannot approve or move money twice; read that `409` as "already approved" and
  check the actual status via `payouts()->info()`.
- For payouts, `order_id` is still required and set by you.

### Idempotency response codes

Routes wrapped in server-side idempotency (`payoutLinks()->create/createBatch` and the other
money-creating calls) may return:

| Code | Error | Retried by SDK | Meaning |
| --- | --- | --- | --- |
| 400 | `idempotency.bad_key` | no | the key is invalid (e.g. longer than 255 characters) |
| 400 | `idempotency.key_reused` | no | the same key was sent with a **different** body |
| 409 | `idempotency.in_progress` | no | the first request with this key is still running — wait and check the result |
| 409 | `payoutlink.duplicate_reference` | no | the `reference` is already taken (used to come back as `500`) |
| 503 | `idempotency.unavailable` | **yes** | the idempotency store is unavailable, the request was rejected fail-closed |

The SDK's classification matches: `ApiException::isRetriable()` is true only for 5xx and 429, so
`400`/`409` are terminal (the auto-retry will not touch them), and `503 idempotency.unavailable` is
retried automatically with the same key.

## Custom HTTP transport

The default is cURL. Plug in your own (Guzzle/PSR-18/a mock) by implementing `Oblodai\Http\Transport`:

```php
$client = new Client($id, $secret, ['transport' => new MyTransport()]);
```

## License

MIT.
