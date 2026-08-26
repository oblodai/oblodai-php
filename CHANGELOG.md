# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versions follow
[Semantic Versioning](https://semver.org/).

## [1.3.0] — 2026-08-26

Rewrite generated from the gateway's contract snapshot (`contract/`). See
[MIGRATION-1.3.md](MIGRATION-1.3.md).

### Fixed

- **Requests are signed with the five-field recipe** (`ts\nMETHOD\nrequestURI\nidempotencyKey\nbody`)
  over path + raw query, with an EMPTY idempotency slot when no key is sent. 1.2 signed four fields
  and got 401 on every call against the current gateway.
- **An unknown value in a closed vocabulary no longer throws.** A status this release has never
  seen used to raise `ContractException` inside a model constructor — which, in a webhook receiver,
  means HTTP 500 for an authentic delivery and 26 hours of redelivery. Closed vocabularies now
  decode into `Oblodai\Contract\Model\OpenEnum`: `->value` is always the raw wire string, `->known`
  is the typed case when this snapshot has one. `Wire::strict()` restores the loud behaviour for
  test suites.
- **A webhook with a valid MAC but an unreadable body is no longer a signature failure.** It is
  `WebhookPayloadException` (a `ContractException`, code `webhook.bad_payload`), so a receiver that
  answers 401 on `SignatureException` does not reject an authentic event. An unknown event `type` is
  not an error at all: it arrives as `UnknownEvent` with the raw body, and `Verifier::isKnownEvent()`
  says which kind you have.
- **An empty webhook secret is refused before any crypto** (`ConfigException`). Verifying with an
  empty key means comparing against `HMAC('', body)`, which any forger can compute. An empty
  `previousSecret` and a negative tolerance are refused the same way.
- **The MAC is checked before the timestamp**, so the freshness window is no longer an oracle an
  unauthenticated caller can probe for the receiver's clock.
- **A request body that cannot be encoded is refused instead of signed as an empty string.**
  Invalid UTF-8, `NAN`/`INF` or nesting past PHP's limit made `json_encode()` return `false`, which
  was cast to `''` — a signed, empty body the caller never wrote.
- **A float where a decimal amount belongs is refused** (`0.1 + 0.2` is not `0.3`). Only the fields
  the contract itself declares as JSON numbers accept one.
- **The error envelope is decoded field by field.** One mangled field no longer costs the other
  five, `retryable` is honoured only as a literal boolean, and `retry_after` (and the `Retry-After`
  header, in both delta-seconds and HTTP-date form) is clamped into `[0, 24 h]` in float space, so
  neither a negative nor a `1e30` can overflow the conversion.
- **`Retry-After` and `Date` are parsed as HTTP dates, not by `strtotime()`**, which read `-3` as a
  relative time and turned garbage into a delay measured in decades.
- **Response bodies are read with a ceiling** — 8 MiB for JSON routes, 64 MiB for document routes —
  and abandoned past it as `TransportException` with code `sdk.response_too_large` (not retryable:
  the same request would produce the same oversized body) instead of exhausting memory.
- **A caller header can no longer shadow a signed one.** Reserved names are compared
  case-insensitively, and a header value carrying CR/LF or a non-ASCII character is refused with
  `sdk.bad_header`.
- **A followed redirect is detected and refused.** The SDK never follows one; when an injected HTTP
  client does, the body came from a URL the signature did not cover.
- **Clock-skew correction is concurrency-safe.** A call remembers the offset it signed with and only
  installs or reverts an offset while the shared value is still the one it saw, so concurrent calls
  converge on one correction instead of undoing each other.
- `Resource::page()` no longer drops path parameters after the first page.
- A caller `idempotencyKey` on a list method is refused (`sdk.idempotency_unsupported`) instead of
  being silently dropped.
- `Verifier::isStale()` returns false for an event without a usable `sequence` instead of treating
  it as stale; `sequence()` is `?int`.
- `Wire::bool()` reads the string `"false"` as false. PHP's own cast makes it `true`, and these
  fields gate finality and refundability.
- `POST /v1/payout/validate` no longer demands `order_id`: the docs table copied it from `create`,
  and the recorded fixture proves the gateway accepts the dry run without it.
- `FileResult::saveTo()` throws on a failed write instead of returning 0, which looked exactly like
  an empty document.
- Big integers survive decoding intact (`JSON_BIGINT_AS_STRING`) instead of becoming lossy floats.
- An empty environment variable means "not set", however the environment is supplied — `OBLODAI_SECRET=`
  no longer configures a client that signs with an empty key.

### Added

- Every merchant route (107) — cancel/validate, batches, documents, fee configs, split opt-in,
  secret rotation, the payer-facing checkout endpoints and the recipient-facing claim endpoints.
- `merchants` namespace: `create()` and `createSandbox()` provision a merchant on a self-hosted
  gateway. They sign nothing and carry `X-Admin-Token` — which now rides ONLY on those routes.
- `wallets->block()` and `wallets->refundBlockedDeposit()`: stop crediting an address and send back
  what landed on it afterwards.
- Rehearsal deliveries are flagged: `Delivery::$isTest`, `WebhookEvent::isTest()` and
  `Verifier::isTestEvent()` are true when the signed body carries `test: true` or the delivery
  carries `X-Webhook-Test`. A rehearsal is signed exactly like a live event and no money moved.
- Route safety comes from the contract's own `safe` field. The SDK used to guess which routes were
  read-only from the path; a guess in that direction re-sends a payout after a timeout.
  `Routes::SPECS` exposes it, and the codegen fails if any route lacks it.
- Secrets redact themselves. `Credentials`, `Config` and the admin token hold their value outside
  the object (`Core\Secret`), so `print_r`, `var_export`, `var_dump`, `json_encode` and `serialize`
  show `[redacted]`. The models that carry a one-time secret — `WebhookEndpoint`,
  `WebhookSecretRotated`, `ApiKeyPair`, `MerchantOnboarded`, `PayoutLink` (`claim_token`,
  `passcode`) and `BatchElement` — mask it in every wholesale rendering while the property stays
  readable. A caller-injected logger is wrapped, so redaction happens before the SDK hands anything
  over.
- `Oblodai\Core\Page`: the first page through `items()`/`paginate()`, every page by iterating,
  nothing requested until consumed. Iteration also stops on a short page, so a server that always
  claims `has_pages` cannot spin it forever.
- `Oblodai\Webhook\Verifier` — rotation-aware verification over the raw bytes, with `parse()` and
  `isStale()`; no client and no API key needed. Signature headers are trimmed and read in either
  hex case; a `0x` prefix is rejected.
- Generated request DTOs (`Oblodai\Contract\Request\*`) carrying English field documentation,
  generated enums (`Oblodai\Contract\Enum\*`) and the route registry (`Oblodai\Contract\Routes`);
  `composer check-drift` fails when they drift from `contract/`.
- Retries driven by the API's own `retryable` flag, automatic idempotency keys, clock-skew
  correction, dual key pairs, per-attempt timeout and per-call deadline.
- Contract tests against the golden response bodies and real signed webhook deliveries, a route
  registry check that compares every flag field by field, and a live journey against a running
  gateway (`composer test-live`).
- `Money::assertAmount()` and a documented 64-character cap on the amounts the helpers accept.
- Per-call headers: `new RequestOptions(headers: ['X-Shop' => 'one'])` merges over the client's own,
  case-insensitively, and still cannot override anything the SDK signs.

### Changed

- PHP ≥ 8.1. Readonly value objects for every response body, each keeping the raw wire body in
  `->raw` and its wire keys in `::KEYS`.
- Errors are an `OblodaiException` hierarchy carrying `errorCode`, `httpStatus`, `retryable`,
  `retryAfter`, `requestId`, `field` and `synthetic`.
- Client-side validation failures are `ConfigException`, not `ValidationException`: nothing was
  sent, so there is no 400 to report. This covers idempotency keys (`sdk.bad_idempotency_key`) and
  amounts (`sdk.bad_amount`), which used to raise `ValidationException` and
  `InvalidArgumentException` respectively.
- HTTP is a small `HttpClient` port — cURL by default, any PSR-18 client through
  `Oblodai\Http\Psr18HttpClient`. The cURL client pins TLS verification and no-redirect after the
  caller's own options, so neither can be switched off from outside, and it does not share one
  handle between concurrent calls.
- `payouts->mass()` and `payoutLinks->batch()` return `list<BatchElement>` rather than the wire's
  `{items}` wrapper; the shape is still asserted, so a change on the core surfaces as a contract
  error rather than an empty list.
- `Credentials::$secret` became `Credentials::secret()` and `Config::$adminToken` is a `Core\Secret`
  — the value no longer lives on the object, which is the only way PHP can keep it out of
  `print_r()`.
- The documentation is English only. `README.ru.md` was dropped in 1.3 and this changelog's older
  entries were translated; the facts are unchanged.
- The distribution archive no longer ships `contract/`, `tests/`, `examples/` or `scripts/`
  (`.gitattributes`); they stay in the repository and in CI.

## [1.2.0] — 2026-07-19

### Breaking

- **A non-https `base_url` is no longer accepted.** The client used to agree to `http://` silently
  and send the request signature (`X-Signature`) in the clear, where anyone on the path can see and
  replay it. The scheme is now checked in the constructor: anything but `https` is a
  `ConfigException`. Loopback is the deliberate exception (`localhost`, `127.0.0.0/8`, `::1`,
  `*.localhost`), so local stands keep working. `OBLODAI_BASE_URL` is covered too.
- `webhooks()->deliveries()` returns the list of deliveries, not the `['deliveries' => [...]]`
  envelope — the only SDK method that made the caller unwrap one. Migration: `$res['deliveries']`
  becomes `$res`.

### Added

- **Developer sandbox** `sandbox()` — five helpers standing in for "the customer paid on-chain".
  They exist only in the sandbox; a live key gets 403 `sandbox.live_key`:
  `simulateDeposit()` (`amount` for under/overpayment, `confirmations` for a stuck deposit;
  repeating with the same `txid` deepens confirmations), `faucet()` (test balance, at most 1000000
  per call), `reset()`, `listWebhooks()` (up to 50 deliveries, newest first) and
  `replayWebhook()`.
- Signed GET requests: `Client::requestGet($path)` — the same canonical HMAC string as POST with an
  empty body.
- `Client::isTestKey($key)` — whether a key is a sandbox key (`test_…` / `oblodai_test_…`).
- **Transfers to platform users** (payout key): `account()->transferToUser()` — an instant, fee-free
  move from the merchant balance to another platform user's personal wallet (`to_user_id` is a
  platform UUID, not a username) — and `account()->transferBatch()`, tracked through
  `batches()->info()`. Both carry `Idempotency-Key`.
- **Public invoice endpoints** for custom checkout pages, unsigned and safe for a front end:
  `payments()->publicGet($uuid)` and `payments()->publicSelect($uuid, $currency, $network)`, which
  finalises a currency-agnostic invoice once the payer picks an asset and network.

### Fixed

- **A `{"state":0,"result":null}` response no longer raises `TypeError`.** The client methods were
  declared `@return mixed` while every resource method promised `: array`; any empty `result` threw
  a `TypeError`, which extends `Error` and therefore flew straight past the README's own
  `catch (OblodaiException)`. All four client methods now return `array`, `null` normalises to `[]`,
  and a scalar `result` would be an `ApiException` (`response.unexpected_shape`).
- **`retry.max_attempts = 0` no longer causes a fatal error.** The retry loop never ran, so the
  method reached its trailing `throw $lastError` with `$lastError === null` — `throw null`, catchable
  by nothing. The value is clamped to `>= 1` (disable retries with `['retry' => false]`), the delays
  are clamped to `>= 0`, and the trailing throw is a real SDK exception.
- **`Retry-After` in HTTP-date form is no longer discarded.** RFC 7231 allows two forms and the
  transport only understood `is_numeric`, so a proxy answering with a date was silently lost and the
  client retried EARLIER than it was asked to. The date is now resolved against "now", clamped into
  `[0, 300]` seconds — the same ceiling as the Node/Python/Go SDKs — and clamped twice, at header
  parsing and at delay calculation, so a third-party transport cannot park the process for a day.
- **A connect timeout was added** (`CURLOPT_CONNECTTIMEOUT_MS`, a third of the total). A dead or
  black-holing host used to eat the whole request budget on one TCP handshake, leaving nothing for
  retries. `0` ("no limit") is preserved as-is.
- **A money seam: an automatic retry could reserve the balance twice.** `payoutLinks()->create()`
  and `createBatch()` reserve funds but travelled WITHOUT an idempotency key, so a lost response
  meant up to three automatic retries and up to four funded links from one balance. Both now send a
  stable `Idempotency-Key`, and the core wraps both routes in server-side idempotency, so a repeat
  replays the first response whole — the same link, the same `claim_token`, `Idempotent-Replayed: true`,
  the balance debited exactly once. Caveats: a partially failed batch replays as-is (push the failed
  items with a new key), and a response over 256 KB is not cached, so always set a per-item
  `reference` on batches.
- A duplicate `reference` on a payout link is now `409 payoutlink.duplicate_reference` instead of
  `500` — which mattered, because `500` was retried into the same error and `409` is terminal.
- `wallets()->blockedAddressRefund()` moved to the idempotent path. The route is deliberately NOT
  wrapped in server-side idempotency: it is already once-only per wallet (stable reference, advisory
  lock, existing-payout lookup inside the lock), and the wrapper would answer a concurrent repeat
  with `409` instead of waiting and succeeding. The address is not part of the reference, so a
  repeat with a different address returns the first payout to the first address.

### Documented

- Idempotency responses on money-creating routes: `400 idempotency.bad_key`,
  `400 idempotency.key_reused` (same key, different body), `409 idempotency.in_progress`,
  `503 idempotency.unavailable` (store down, fail-closed), `409 payoutlink.duplicate_reference`.
  Checked against the SDK's classification: only 5xx and 429 are retriable, so `400`/`409` are
  terminal and `503` is repeated with the same key.
- `payouts()->approve()` needs no idempotency and sends none: it is a state transition, accepted
  only from `pending`, and `409 payout.not_pending` should be read as "already approved".
- **"Where to get keys" moved to the first block after installation** — the top complaint in all
  five reviews. Keys come from the Oblodai dashboard; the secret is shown once; the sandbox issues
  a `test_…` key.
- **The sandbox moved up, right after the quick start.** It used to sit behind batches, splits and
  payout links, so a reader working top to bottom wired LIVE keys before learning there was a safe
  place to integrate against.
- **One name for the payment-links resource.** The canon across all five SDKs is `payment_links`
  (`paymentLinks()` in PHP/JS, `payment_links` in Python/Rust, `PaymentLinks()` in Go). Both names
  existed in PHP; `paymentLinks()` is canonical and `links()` a documented alias. Nothing was
  removed.
- The error-handling section lists the exception hierarchy and records the new contract: resource
  methods return an array for any gateway response, and an empty `result` is `[]`.
- **Removed a false claim about sandbox deposits "maturing".** A simulated deposit is never
  re-emitted and its cursor never moves, so an invoice sits in `confirm_check` indefinitely; the
  only way to `paid` is to repeat `simulateDeposit` with the SAME `txid` and more confirmations.
  The ~10 minutes belong to the payout maturity hold (`payout.funds_maturing`), which the sandbox
  lifts by age.
- The README no longer claims `/v1/payout/link*` does not support `Idempotency-Key`.
- **Registering a webhook is an upsert of the project's single endpoint.** The core does
  `INSERT ... ON CONFLICT (project_id) DO UPDATE SET url = EXCLUDED.url`, so a second call with a
  different URL REDIRECTS deliveries rather than adding an endpoint — the classic accident where a
  staging URL registered from a local script kills production webhooks. The secret survives
  re-registration, or already-queued deliveries signed with it would go stale. The signing secret is
  the ENDPOINT's secret, not the API key's: using the API key rejects 100% of webhooks.
- A payment-status table, with terminality and the `is_final` flag, separating
  `wrong_amount_waiting` (partially paid, invoice still alive) from `wrong_amount` (closed
  underpaid) — `resolve()` accepts only the latter and answers `409 resolution.not_underpaid` for
  the former, which is terminal, not a reason to retry.
- **A caveat about empty links.** `payment['url']`, `link['url']` and `check['claim_url']` are built
  from the gateway's public base URL; without it they arrive as an empty string. Impossible in
  production, common on a local stand, and not an SDK bug — the README shows how to build the link
  from `uuid` / `claim_token`.
- **`sandbox()->reset()` is not a clean slate.** Only invoices in `created` and `select` are
  cancelled; one with a visible deposit is deliberately left alone, since cancelling it would let
  the deposit confirm into a cancelled invoice. Nothing is deleted: the ledger is append-only and
  zeroing a balance is a compensating entry.

## [1.1.0] — 2026-07-15

### Breaking

- Idempotency moved from auto-filling `order_id` to the `Idempotency-Key` header:
  - `payments()->create()` and `account()->transferToPersonal()` no longer generate an `order_id`
    (`idem-…`) when none is given; it travels as-is. Set it explicitly if your code relied on the
    generated value.
  - Every creating call instead sends `Idempotency-Key` (UUID v4) generated ONCE before the retry
    loop, so every internal retry carries the same value and a repeat after a timeout creates no
    duplicate. The header is part of neither the signature nor the body.
  - Your own key goes in the optional `idempotency_key` parameter (or `$idempotencyKey` on batch
    methods) — into the header, not the body.
  - Exception: `payoutLinks()` (`/v1/payout/link*`) does not support the header; deduplication there
    is the per-link `reference`.

### Added

- **Batch operations** (up to 5000 items per request): `payments()->createBatch()`,
  `payments()->refundBatch()`, `payouts()->createBatch()` and `batches()->info()` for per-item
  progress and results. `on_error`: `continue` (default) or `stop`.
- **Payment links** `links()` (alias `paymentLinks()`): `create`, `list`, `info`, `toggle`, plus the
  unsigned `publicGet` and `checkout`.
- **Payout links (crypto cheques)** `payoutLinks()`: `create`, `createBatch` (up to 500), `list`,
  `info`, `cancel`, plus the unsigned `claimInfo($token)` and `claim($token, $address, $memo)`. Set
  `expires_in_hours` explicitly — without it the backend clamps the link's life to one hour.
- **Split payments** `splits()`: `splitToAddress`, `splitToMerchant`, `createRule`, `listRules`,
  `deleteRule`, `getConfig`, `setConfig` (hold window `refund_hold_hours`).
- **Invoice by e-mail**: `payments()->sendEmail($uuid, $orderId, $email)` — backend limit is 10
  messages per hour per address.
- **Deciding an underpayment**: `payments()->resolve(['uuid'|'order_id' => …, 'action' => 'accept'|'refund'])`
  — keep the partial payment or return it to the payer (this also silences the auto-refund).

## [1.0.2] — 2026-07-12

### Fixed

- A malformed `Retry-After` no longer breaks retries: a negative value (`Retry-After: -5`) used to
  produce a negative delay and a `ValueError` out of `usleep()`. The delay is clamped at zero.
- The "empty `order_id`" check for auto-idempotency was normalised: the key is filled in when the
  given value is not a non-empty string after `trim()`, covering `null`, `''`, whitespace and
  non-string values. A real caller `order_id` is preserved.

## [1.0.1] — 2026-07-12

### Fixed

- Retry safety: `payments()->create()` and `account()->transferToPersonal()` fill in a stable
  `order_id` (`idem-…`) when none is given, so a retry of a non-idempotent POST after a network or
  5xx failure creates no duplicate. `payouts()` still requires an explicit `order_id`.
- `Retry-After` is honoured beyond `max_delay_ms` (clamped only by the absolute 300 000 ms ceiling):
  `Retry-After: 60` waits ~60 s instead of being cut to `max_delay`.
- Real random jitter in the backoff (`random_int`) instead of a constant, which reduces the
  thundering-herd effect on simultaneous retries.
- `payout.funds_maturing` is no longer treated as transient (`isRetriable()` → terminal): repeating
  does not resolve it.
- `ApiException` docblock: the example `$e->getCode2()` corrected to `$e->getErrorCode()`.

## [1.0.0] — 2026-07-12

### Added

- First release of the official PHP SDK for the Oblodai payment gateway.
- Accepting payments, payouts and mass payouts, static wallets, refunds, webhooks, and the public
  catalogues (exchange rates, coins and networks).
- HMAC-SHA256 request signing and webhook signature verification (constant time, replay-protected).
- `Client::fromEnv()` — `OBLODAI_PUBLIC_ID` / `OBLODAI_SECRET` / `OBLODAI_BASE_URL`.
- Automatic retries with exponential backoff, honouring `Retry-After` on 429.
- Injectable HTTP transport (cURL by default, no external dependencies).
