# Migrating to 2.0

2.0 is the SDK regenerated from the gateway's OpenAPI contract. The resources, methods, models and
enums are generated (`src/Generated`, namespace `Oblodai\Generated`), so their names follow the
contract's `operationId`s; the hand-written runtime underneath — signing, retries, idempotency,
clock skew, webhooks — is the one 1.3 had, with the fixes listed in [CHANGELOG.md](CHANGELOG.md).

The short version:

1. PHP **8.2** or newer.
2. Timeouts are **seconds**: `timeoutMs: 30000` → `timeout: 30`, `deadlineMs: 90000` → `deadline: 90`
   — on the client and in `RequestOptions`.
3. Methods have new names (table below; `names.lock` pins them from now on).
4. Lookups take an array: `payments->info('uuid')` → `payments->getInfo(['uuid' => 'uuid'])`.
5. Models live in `Oblodai\Generated\Model` and statuses are enum cases or plain strings (no
   `OpenEnum`); read a status with `Status::value($x->status)`.
6. `getMessage()` of an error is now `[code] text (request_id=…)`; the text alone is `$err->detail`.

## Client and call options

| 1.3 | 2.0 |
| --- | --- |
| `new Oblodai(timeoutMs: 30000, deadlineMs: 90000)` | `new Oblodai(timeout: 30, deadline: 90)` — seconds |
| `new RequestOptions(idempotencyKey:, timeoutMs:, deadlineMs:, headers:)` | `new RequestOptions(idempotencyKey:, timeout:, maxRetries:, extraHeaders:, requestId:)` |
| per-call deadline (`deadlineMs`) | the client's `deadline` (per call: `withOptions()` or `timeout`) |
| — | `maxRetries` per call, `requestId` (sent as `X-Request-ID`) |
| — | `$oblodai->withOptions(timeout:, maxRetries:, extraHeaders:)` — a copy with other defaults |
| — | `new Oblodai(hooks: new Hooks(onRequest:, onResponse:))` |
| — | `$resource->withRawResponse(fn ($r) => $r->method(…))` — status, headers, request id |

Every call now sends its own `X-Request-ID` (the same on each retry of it); errors carry it when the
gateway's envelope names none.

## Method names

Every 1.x method and its 2.0 name, by route. The 1.x aliases (`get`/`info`, `list`/`history`)
collapse into the one generated name.

| 1.x | 2.0 | route |
| --- | --- | --- |
| `account->balance()` | `account->getBalance()` | `POST /v1/balance` |
| `account->referral()` | `referrals->getInfo()` | `POST /v1/referral/info` |
| `account->vrcs()` | `settings->configureVrcs()` | `POST /v1/vrcs` |
| `batches->info()` | `batches->getInfo()` | `POST /v1/batch/info` |
| `catalog->currencies()` | `checkout->listCurrencies()` | `GET /v1/currencies` |
| `catalog->exchangeRates()` | `account->listExchangeRates()` | `POST /v1/exchange-rate/list` |
| `documents->balanceCertificate()` | `documents->getBalance()` | `GET /v1/documents/balance` |
| `documents->batchReport()` | `documents->getBatch()` | `GET /v1/documents/batch` |
| `documents->createJob()` | `documents->createJob()` | `POST /v1/documents/jobs` |
| `documents->download()` | `documents->getSigned()` | `GET /v1/documents/{kind}/{id}` |
| `documents->feeSchedule()` | `documents->getFees()` | `GET /v1/documents/fees` |
| `documents->jobFile()` | `documents->downloadJobFile()` | `GET /v1/documents/jobs/file` |
| `documents->jobInfo()` | `documents->getJob()` | `POST /v1/documents/jobs/info` |
| `documents->ledger()` | `documents->getLedger()` | `GET /v1/documents/ledger` |
| `documents->linkReport()` | `documents->getPaymentLink()` | `GET /v1/documents/link` |
| `documents->referralsReport()` | `documents->getReferrals()` | `GET /v1/documents/referrals` |
| `documents->splitReport()` | `documents->getSplit()` | `GET /v1/documents/split` |
| `documents->statement()` | `documents->getStatement()` | `GET /v1/documents/statement` |
| `documents->walletStatement()` | `documents->getWalletStatement()` | `GET /v1/documents/wallet/statement` |
| `merchants->create()` | — (not in the merchant contract; onboarding is the gateway's own) | `POST /v1/merchants` |
| `merchants->createSandbox()` | `sandbox->onboardStore()` | `POST /v1/merchants/{id}/sandbox` |
| `paymentLinks->checkout()` | `checkout->paymentLink()` | `POST /v1/link/{id}/checkout` |
| `paymentLinks->create()` | `paymentLinks->create()` | `POST /v1/payment/link` |
| `paymentLinks->get()` | `paymentLinks->get()` | `POST /v1/payment/link/info` |
| `paymentLinks->info()` | `paymentLinks->get()` | `POST /v1/payment/link/info` |
| `paymentLinks->list()` | `paymentLinks->list()` | `POST /v1/payment/link/list` |
| `paymentLinks->publicView()` | `checkout->getPublicPaymentLink()` | `GET /v1/link/{id}` |
| `paymentLinks->toggle()` | `paymentLinks->toggle()` | `POST /v1/payment/link/toggle` |
| `payments->batch()` | `batches->createPayment()` | `POST /v1/payment/batch` |
| `payments->cancel()` | `payments->cancel()` | `POST /v1/payment/cancel` |
| `payments->create()` | `payments->create()` | `POST /v1/payment` |
| `payments->get()` | `payments->getInfo()` | `POST /v1/payment/info` |
| `payments->history()` | `payments->listHistory()` | `POST /v1/payment/history` |
| `payments->info()` | `payments->getInfo()` | `POST /v1/payment/info` |
| `payments->list()` | `payments->listHistory()` | `POST /v1/payment/history` |
| `payments->publicQr()` | `checkout->getQr()` | `GET /v1/pay/{id}/qr` |
| `payments->publicView()` | `checkout->get()` | `GET /v1/pay/{id}` |
| `payments->qr()` | `payments->getQr()` | `POST /v1/payment/qr` |
| `payments->resend()` | `webhooks->resendPayment()` | `POST /v1/payment/resend` |
| `payments->select()` | `checkout->selectMethod()` | `POST /v1/pay/{id}/select` |
| `payments->sendEmail()` | `payments->sendEmail()` | `POST /v1/payment/send-email` |
| `payments->services()` | `payments->listServices()` | `POST /v1/payment/services` |
| `payoutLinks->batch()` | `payoutLinks->createBatch()` | `POST /v1/payout/link/batch` |
| `payoutLinks->cancel()` | `payoutLinks->cancel()` | `POST /v1/payout/link/cancel` |
| `payoutLinks->cheque()` | `documents->getPayoutLinkCheque()` | `POST /v1/payout/link/cheque` |
| `payoutLinks->claim()` | `payoutLinks->claimPayout()` | `POST /v1/claim/{token}` |
| `payoutLinks->claimPreview()` | `payoutLinks->getPayoutClaim()` | `GET /v1/claim/{token}` |
| `payoutLinks->create()` | `payoutLinks->create()` | `POST /v1/payout/link` |
| `payoutLinks->get()` | `payoutLinks->get()` | `POST /v1/payout/link/info` |
| `payoutLinks->info()` | `payoutLinks->get()` | `POST /v1/payout/link/info` |
| `payoutLinks->list()` | `payoutLinks->list()` | `POST /v1/payout/link/list` |
| `payouts->approve()` | `payouts->approve()` | `POST /v1/payout/approve` |
| `payouts->batch()` | `batches->createPayout()` | `POST /v1/payout/batch` |
| `payouts->calculate()` | `payouts->calculate()` | `POST /v1/payout/calculate` |
| `payouts->cancel()` | `payouts->cancel()` | `POST /v1/payout/cancel` |
| `payouts->create()` | `payouts->create()` | `POST /v1/payout` |
| `payouts->get()` | `payouts->getInfo()` | `POST /v1/payout/info` |
| `payouts->getFeeConfig()` | `settings->getPayoutFeeConfig()` | `POST /v1/payout/fee-config/get` |
| `payouts->getRefundFeeConfig()` | `settings->getRefundFeeConfig()` | `POST /v1/payout/refund-fee-config/get` |
| `payouts->history()` | `payouts->listHistory()` | `POST /v1/payout/history` |
| `payouts->info()` | `payouts->getInfo()` | `POST /v1/payout/info` |
| `payouts->list()` | `payouts->listHistory()` | `POST /v1/payout/history` |
| `payouts->mass()` | `payouts->createMass()` | `POST /v1/payout/mass` |
| `payouts->services()` | `payouts->listServices()` | `POST /v1/payout/services` |
| `payouts->setFeeConfig()` | `settings->setPayoutFeeConfig()` | `POST /v1/payout/fee-config/set` |
| `payouts->setRefundFeeConfig()` | `settings->setRefundFeeConfig()` | `POST /v1/payout/refund-fee-config/set` |
| `payouts->validate()` | `payouts->validate()` | `POST /v1/payout/validate` |
| `refunds->batch()` | `batches->createRefund()` | `POST /v1/refund/batch` |
| `refunds->create()` | `refunds->payment()` | `POST /v1/payment/refund` |
| `refunds->resolve()` | `payments->resolve()` | `POST /v1/payment/resolve` |
| `sandbox->deposit()` | `sandbox->simulateDeposit()` | `POST /v1/sandbox/deposit` |
| `sandbox->faucet()` | `sandbox->faucet()` | `POST /v1/sandbox/faucet` |
| `sandbox->replay()` | `sandbox->replayWebhook()` | `POST /v1/sandbox/webhooks/replay` |
| `sandbox->reset()` | `sandbox->reset()` | `POST /v1/sandbox/reset` |
| `sandbox->webhooks()` | `sandbox->listWebhooks()` | `GET /v1/sandbox/webhooks` |
| `settings->addApiAllowlist()` | `apiAllowlist->addEntry()` | `POST /v1/api-allowlist/add` |
| `settings->deleteAutoWithdraw()` | `settings->deleteAutoWithdrawRule()` | `POST /v1/auto-withdraw/delete` |
| `settings->enableApiAllowlist()` | `apiAllowlist->setEnabled()` | `POST /v1/api-allowlist/enable` |
| `settings->getAccuracy()` | `settings->getAccuracy()` | `POST /v1/payment/accuracy/get` |
| `settings->getAutoRefund()` | `settings->getAutoRefund()` | `POST /v1/payment/autorefund/get` |
| `settings->getPaymentFeeConfig()` | `settings->getPaymentFeeConfig()` | `POST /v1/payment/fee-config/get` |
| `settings->listAccepted()` | `settings->listAcceptedCurrencies()` | `POST /v1/payment/accepted/list` |
| `settings->listApiAllowlist()` | `apiAllowlist->list()` | `POST /v1/api-allowlist/list` |
| `settings->listAutoWithdraw()` | `settings->listAutoWithdrawRules()` | `POST /v1/auto-withdraw/list` |
| `settings->listDiscounts()` | `settings->listDiscounts()` | `POST /v1/payment/discount/list` |
| `settings->removeApiAllowlist()` | `apiAllowlist->removeEntry()` | `POST /v1/api-allowlist/remove` |
| `settings->setAccepted()` | `settings->setAcceptedCurrencies()` | `POST /v1/payment/accepted/set` |
| `settings->setAccuracy()` | `settings->setAccuracy()` | `POST /v1/payment/accuracy/set` |
| `settings->setAutoRefund()` | `settings->setAutoRefund()` | `POST /v1/payment/autorefund/set` |
| `settings->setAutoWithdraw()` | `settings->setAutoWithdrawRule()` | `POST /v1/auto-withdraw/set` |
| `settings->setDiscount()` | `settings->setDiscount()` | `POST /v1/payment/discount/set` |
| `settings->setPaymentFeeConfig()` | `settings->setPaymentFeeConfig()` | `POST /v1/payment/fee-config/set` |
| `splits->createRule()` | `splits->createRule()` | `POST /v1/split/rule` |
| `splits->deleteRule()` | `splits->deleteRule()` | `POST /v1/split/rule/delete` |
| `splits->getConfig()` | `splits->getConfig()` | `POST /v1/split/config/get` |
| `splits->getOptIn()` | `splits->getRecipientOptIn()` | `POST /v1/split/recipient/optin/get` |
| `splits->listRules()` | `splits->listRules()` | `POST /v1/split/rule/list` |
| `splits->setConfig()` | `splits->setConfig()` | `POST /v1/split/config/set` |
| `splits->setOptIn()` | `splits->setRecipientOptIn()` | `POST /v1/split/recipient/optin` |
| `transfers->batch()` | `payouts->createTransferBatch()` | `POST /v1/transfer/batch` |
| `transfers->toPersonal()` | `payouts->transferToPersonal()` | `POST /v1/transfer/to-personal` |
| `transfers->toUser()` | `payouts->transferToUser()` | `POST /v1/transfer/to-user` |
| `wallets->block()` | `wallets->block()` | `POST /v1/wallet/block` |
| `wallets->create()` | `wallets->create()` | `POST /v1/wallet` |
| `wallets->qr()` | `wallets->getQr()` | `POST /v1/wallet/qr` |
| `wallets->refundBlockedDeposit()` | `refunds->blockedWallet()` | `POST /v1/wallet/blocked-address-refund` |
| `webhooks->deliveries()` | `webhooks->listDeliveries()` | `POST /v1/webhooks/deliveries` |
| `webhooks->register()` | `webhooks->register()` | `POST /v1/webhooks` |
| `webhooks->rotateSecret()` | `webhooks->rotateSecret()` | `POST /v1/webhooks/rotate-secret` |
| `webhooks->test()` | `webhooks->sendTestPayment() / sendTestPayout() / sendTestWallet() / sendTestConversion()` | `POST /v1/test-webhook/{kind}` |
| `webhooks->testLegacy()` | `webhooks->sendLegacyTest()` | `POST /v1/payment/testing-webhook` |

New in 2.0 (no 1.x method): `account->getSummary()`, `checkout->getOnramp()`,
`checkout->startOnramp()`, `checkout->getSourceOfFundsForm()`, `checkout->submitSourceOfFunds()`,
`payments->getAmlLinks()`, `payments->getCheckoutConfig()`, `payments->setCheckoutConfig()`,
`settings->getAutoConvert()`, `settings->setAutoConvert()`, `settings->listApiLog()`,
`webhooks->setActive()`, `webhooks->requeueDelivery()`, `webhooks->sendTestConversion()`.

## Arguments

- **Bodies** are an array or the generated request model, never a bare id:
  `payouts->approve('p1')` → `payouts->approve(['uuid' => 'p1'])`,
  `payments->info('u')` → `payments->getInfo(['uuid' => 'u'])`.
- **Path parameters** come first: `checkout->get($invoiceUuid)`, `payoutLinks->claimPayout($token,
  ['address' => …])`.
- **Query parameters** of GET routes are named arguments: `documents->getStatement(from:
  '2026-01-01', to: '2026-01-31', format: 'csv')`, `sandbox->listWebhooks(limit: 10)`.
- **Request DTOs** moved: `Oblodai\Contract\Request\PaymentRequest` →
  `Oblodai\Generated\Model\PaymentRequest` (named arguments as before; enums are the generated
  `Oblodai\Generated\Enum\*` cases or plain strings). The request model names follow the contract,
  so some changed — the method's docblock names the model it takes.
- A **float** in a body is `sdk.float_amount` (it was `sdk.bad_config`).

## Models and statuses

- Response models are `Oblodai\Generated\Model\*`, named as in the contract (`PaymentView`,
  `PayoutView`, `BalanceResult`, …); the method's return type names each one. Properties keep the
  wire names (`$invoice->payer_amount`).
- `OpenEnum` is gone: a closed vocabulary is the enum case when this SDK knows the value, else the
  plain string (`PaymentStatus|string`). `$x->status->value` → `Status::value($x->status)`;
  `$x->status->is('paid')` → `$x->status === PaymentStatus::Paid` or `Status::isPaymentPaid()`.
- `->raw` is gone: fields newer than the SDK are in `->extra`, and `->toArray()` gives the wire body.
- Lists that were plain arrays (`settings->listAutoWithdraw()`, the api-allowlist methods,
  `payouts->mass()`, `payoutLinks->batch()`) now return their result model with an `items` field.
- Paged lists still return `Page` (`items()`, `paginate()`, `foreach`, `all()`); new are `first()`
  and `byPage()` (a `PageResult` per page).
- `Oblodai\Contract\Enums::ERROR_CODES` → the enum `Oblodai\Generated\Enum\ErrorCode`.

## Batches and document exports

`payments->batch()` & co. returned a `BatchSubmitted` ticket to poll by hand. The same calls
(`batches->createPayment()` …) return the submit answer, and `asJob()` waits for you:

```php
$job = $oblodai->batches->asJob(fn (Batches $b) => $b->createPayout($batch));
$info = $job->wait();           // BatchInfoResponse, status completed or stopped
```

`documents->createJob()` + `jobInfo()` + `jobFile()` → `documents->asJob(fn ($d) =>
$d->createJob($export))->wait()` and `->download()`.

## Webhooks

- `Verifier::verify()` returns a `Delivery` whose `event` is the verified body as an array (it was
  `PaymentEvent|PayoutEvent|WalletEvent|UnknownEvent`). `Verifier::model($delivery->event)` gives
  the generated `PaymentWebhook`, `PayoutWebhook`, `WalletWebhook` or `ConversionWebhook` — or
  null for a type this SDK does not model (it was `UnknownEvent`).
- `Verifier::isKnownEvent()`, `isTestEvent()`, `isStale()` take the array.
- New: `$delivery->eventId` (`X-Webhook-Event-Id`) — deduplicate on it rather than on
  `$delivery->id`.

## Errors

- `getMessage()` / `(string) $err` read `[code] text (request_id=…)`; `$err->detail` is the text.
  `json_encode($err)` still has `message` = the text.
- New codes: `sdk.float_amount`, `sdk.lro_unresolved`.

## Repository

`contract/`, `src/Contract` and `composer codegen`/`check-drift` are gone: the code is generated by
the backend's `tools/sdkgen`, and `make ci` (docker) runs every gate, including the drift check and
the shared conformance suite.
