<div align="center">

<a href="https://oblodai.com">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/oblodai/.github/main/brand/logo-white.svg">
    <img src="https://raw.githubusercontent.com/oblodai/.github/main/brand/logo-black.svg" alt="oblodai" height="52">
  </picture>
</a>

<h3>Официальный PHP SDK для платёжного шлюза <a href="https://oblodai.com">oblodai</a></h3>

Платежи, выплаты, платёжные ссылки, сплиты, статические кошельки, вебхуки — один API-ключ.

<a href="https://packagist.org/packages/oblodai/sdk"><img src="https://img.shields.io/packagist/v/oblodai/sdk?style=flat-square&label=Packagist" alt="Packagist"></a>
<a href="https://github.com/oblodai/oblodai-php/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/oblodai/oblodai-php/ci.yml?branch=main&style=flat-square&label=CI" alt="CI"></a>
<a href="https://packagist.org/packages/oblodai/sdk"><img src="https://img.shields.io/packagist/php-v/oblodai/sdk?style=flat-square" alt="PHP version"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-000000?style=flat-square" alt="License: MIT"></a>

[Documentation](https://docs.oblodai.com) · [Dashboard](https://my.oblodai.com) · [Read in English →](README.md)

</div>

---

Официальный PHP SDK для платёжного шлюза **Oblodai**: приём платежей, выплаты, массовые операции
(батчи), платёжные ссылки, выплатные ссылки (крипточеки), сплиты, статические кошельки, переводы,
вебхуки. Подпись запросов, разбор ответов, типизированные ошибки, идемпотентность и повторы — из
коробки.

PHP ≥ 8.2 с `ext-json` и `ext-curl`, PSR-4 и `declare(strict_types=1)` везде, readonly-модель на
каждое тело запроса и ответа и никаких других зависимостей в рантайме, кроме PSR-интерфейсов HTTP:
из коробки работает cURL, но его место может занять любой PSR-18-клиент. Ресурсы, методы и модели
генерируются из OpenAPI-контракта шлюза, поэтому SDK всегда говорит на том API, с которым выпущен.

> **Базовый URL.** По умолчанию `https://api.oblodai.com`. При необходимости укажите свой `baseUrl` и
> свои ключи при инициализации. Схема должна быть `https://`; обычный `http://` (и на петлевом адресе
> тоже) принимается только с явной опцией разрешения незащищённого соединения
> (`allowInsecureBaseUrl: true` либо `OBLODAI_ALLOW_INSECURE=1`), а `user:password@` отклоняется.

## Установка

```bash
composer require oblodai/sdk
```

PHP 8.2 или новее с `ext-json` и `ext-curl`. Composer подтянет PSR-интерфейсы HTTP
(`psr/http-client`, `psr/http-factory`, `psr/http-message`); `psr/log` необязателен и нужен только,
чтобы вести журнал SDK через Monolog или другой PSR-3-логгер. Переход с 1.x — см.
[MIGRATION-2.0.md](MIGRATION-2.0.md).

## Где взять ключи

У мерчанта **один** API-ключ, он выдаётся в кабинете [my.oblodai.com](https://my.oblodai.com) →
**API-ключи**. Это публичный идентификатор и секрет; секрет только подписывает запрос и никогда не
отправляется.

| ключ                 | публичный id          | секрет                | что открывает                                                        |
| -------------------- | --------------------- | --------------------- | -------------------------------------------------------------------- |
| **боевой API-ключ**  | `oblodai_<hex>`       | `oblodai_live_<hex>`  | весь API мерчанта: приём, выплаты, настройки, документы              |
| **ключ песочницы**   | `test_oblodai_<hex>`  | `oblodai_test_<hex>`  | тот же API на песочнице                                              |
| операции оператора   | —                     | —                     | не поддерживаются: `sandbox->onboardStore()` бросает `sdk.operator_channel_unsupported` — используйте панель |

Эта пара подписывает каждый маршрут, который шлюз закрывает ключом, — выбирать на каждый вызов
нечего:

```php
use Oblodai\Oblodai;

$oblodai = new Oblodai(publicId: $publicId, secret: $secret);
```

## Быстрый старт

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

Чтобы выставить цену в фиате, добавьте `to_currency`: `['amount' => '25', 'currency' => 'USD',
'to_currency' => 'USDT']` — `currency` — во что вы оцениваете, `to_currency` — какой актив
отправляет плательщик.

Выплата устроена так же — её подписывает тот же ключ — со своим ключом идемпотентности, чтобы
повтор после перезапуска не отправил деньги дважды:

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

Тело запроса — массив (его форма описана в докблоке метода, и редактор дополняет ключи) или
сгенерированная модель запроса с именованными аргументами:

```php
use Oblodai\Generated\Model\PaymentRequest;

$invoice = $oblodai->payments->create(new PaymentRequest(
    amount: '25',
    currency: 'USDT',
    network: 'tron',
    order_id: 'order-1002',
));
```

Готовые к запуску версии всего этого лежат в [`examples/`](examples); набор тестов прогоняет их — и
каждый блок кода этого README — на имитации шлюза.

## Песочница и тестирование

Ключ песочницы (`test_oblodai_…`) работает с копией шлюза без блокчейна: тестовый баланс из крана,
имитация поступлений, настоящие подписанные вебхуки. Интегрируйтесь сначала с ней — ничто здесь не
касается сети.

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

Репетиционную доставку можно запросить и на боевом: `webhooks->sendTestPayment(['url_callback' =>
…, 'status' => 'paid'])` пришлёт образец события, подписанный в точности как настоящий, с `test:
true` в теле. Никогда не двигайте по нему деньги — см. [Вебхуки](#вебхуки). Боевой ключ получает 403
`sandbox.live_key` на помощниках песочницы.

## Обзор методов

Каждая операция контракта шлюза — `$oblodai-><пространство>-><метод>()`, названная по её
`operationId` (старые имена 1.x — в [MIGRATION-2.0.md](MIGRATION-2.0.md)). Таблицу пишет
генератор:

<!-- sdkgen:methods -->
17 ресурсов, 124 метода.

| Ресурс | Методы |
| --- | --- |
| `payments` | `create` · `getInfo` · `getQr` · `listHistory` · `listServices` · `cancel` · `sendEmail` · `setCheckoutConfig` · `getCheckoutConfig` · `getAmlLinks` · `resolve` |
| `paymentLinks` | `create` · `list` · `get` · `toggle` |
| `refunds` | `payment` · `calculate` · `blockedWallet` |
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

Каждый метод принимает необязательный последний аргумент `RequestOptions` — см.
[Повторы, идемпотентность и таймауты](#повторы-идемпотентность-и-таймауты). Параметры пути идут
первыми (`$oblodai->checkout->get($uuid)`), параметры запроса — именованными аргументами
(`$oblodai->documents->getStatement(from: '2026-01-01', to: '2026-01-31', format: 'csv')`), тело —
массив или модель запроса. Докблок каждого метода несёт описание шлюза, маршрут и коды ошибок,
которыми он может ответить.

### Списки

Постраничные методы возвращают `Oblodai\Core\Page` — ленивый объект: пока вы его не используете,
ничего не запрашивается. `foreach` идёт по всем элементам всех страниц, `byPage()` — по страницам,
`first()` (или `items()` / `paginate()`) — только по первой. Обход останавливается, когда шлюз
отвечает `has_pages: false` или присылает неполную страницу — что наступит раньше.

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

### Долгие операции

Массовые пакеты (`batches->createPayment/createPayout/createRefund`, `payouts->createTransferBatch`)
и выгрузки документов (`documents->createJob`) доделываются в фоне. `asJob()` оборачивает вызов
создания и возвращает `Oblodai\Core\Job`: `->id`, ответ создания `->result` и `->wait()`, который
опрашивает операцию, пока статус не станет конечным (`completed`/`stopped` у пакета,
`done`/`failed`/`expired` у выгрузки), и возвращает последний ответ — неудачная операция
возвращается, а не бросается.

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

Какие операции — долгие и как за каждой следить, решает контракт (`x-sdk-poll`): это сгенерировано
в `Oblodai\Generated\Facts` и читается через `Oblodai\Lro::JOBS`.

### Статусы

- Платёж: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`
  (и `under_review`). `Status::isPaymentPaid()` истинно для `paid`/`paid_over`; `wrong_amount`
  (недоплата) ждёт `payments->resolve(['uuid' => …, 'action' => 'accept'|'refund'])`;
  `Status::isPaymentFinal()` покрывает остальные.
- Выплата: `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.

Какие статусы конечные и какие из них означают успех, решает контракт (`x-status-classes`): это
несут сгенерированные enum'ы (`PaymentStatus::FINAL`, `PaymentStatus::Paid->isSuccess()`), а
хелперы `Status` — обёртки над ними.

Об изменениях состояния лучше узнавать из вебхуков; `getInfo()` — только запасной путь.

Статус (и любой другой закрытый словарь) — вариант сгенерированного enum'а, а для значения новее
этого SDK — просто строка с провода. Незнакомое значение никогда не роняет разбор: шлюз добавляет
статусы по своему расписанию, и приёмник вебхуков, отказавшийся от первого незнакомого, ответил бы
500 на подлинную доставку и получал бы её повторно целые сутки.

```php
use Oblodai\Generated\Enum\PaymentStatus;

$payment = $oblodai->payments->getInfo(['uuid' => $invoice->uuid]);
$payment->status === PaymentStatus::Paid;   // a known value is the case
Status::value($payment->status);            // "paid" — the wire string, known or not
Status::isPaymentPaid($payment->status);    // false for a status this SDK does not know
$payment->extra;                            // fields newer than this SDK, exactly as received
```

### Деньги

Суммы — десятичные **строки** в обе стороны: модели типизируют их как `string`, а число с плавающей
точкой в любом месте тела запроса (кроме чисел, которые контракт типизирует как `number`, — не
денег, `Oblodai\Generated\Facts::NON_MONEY_NUMBERS`) — ошибка `sdk.float_amount` ещё до сети. `Oblodai\Helper\Money::add()`,
`subtract()`, `compare()`, `equals()`, `isZero()`, `isPositive()`, `assertAmount()` — точная
десятичная арифметика над этими строками. Никогда не приводите денежное поле к `float` и не
сравнивайте суммы как строки (`"9"` как текст больше `"10"`, а как деньги — меньше; используйте
`compare()`).

## Вебхуки

Зарегистрируйте адрес через `webhooks->register(['url' => …])` — секрет подписи возвращается один
раз, сохраните его сразу. Проверяйте каждую доставку по **сырым** байтам запроса
(`file_get_contents('php://input')`) и заголовкам (`getallheaders()`); пересобранный из разбора JSON
не совпадёт.

```php
use Oblodai\Generated\Model\PaymentWebhook;
use Oblodai\Webhook\Verifier;

$delivery = Verifier::verify(
    rawBody: $rawBody,                      // the RAW bytes, never a re-encoded parse
    headers: $headers,
    secret: (string) getenv('OBLODAI_WEBHOOK_SECRET'),
);

if ($delivery->isTest) {                    // a rehearsal (signed body `test: true`) — no money moved
    http_response_code(200);
} else {
    $event = Verifier::model($delivery->event);   // PaymentWebhook | PayoutWebhook | WalletWebhook | ConversionWebhook | null
    if ($event instanceof PaymentWebhook && Status::isPaymentPaid($event->status)) {
        markOrderPaid($event->order_id);
    }
    http_response_code(200);
}
```

`Verifier` не нужен ни клиент, ни API-ключ. Отвечайте правильным статусом на правильный отказ:

| исключение                  | что случилось                                  | ответ                 |
| --------------------------- | ---------------------------------------------- | --------------------- |
| `ConfigException`           | приёмник настроен неверно (нет секрета)        | 500, и исправьте      |
| `SignatureException`        | доставка не наша или слишком старая            | 401                   |
| `WebhookPayloadException`   | доставка наша, но тело не читается             | 2xx (или 400) + алерт |

`webhook.bad_payload` намеренно НЕ относится к семейству подписи: MAC уже доказал подлинность
события, а ответ 401 заставил бы шлюз повторять доставку сутки. **401 — только для отказа подписи.**
Незнакомый этому SDK `type` события — тоже не отказ: `$delivery->event` хранит тело,
`Verifier::model()` возвращает null, а `Verifier::isKnownEvent()` — false.

`$delivery->eventKey` — `event_id` из подписанного тела (запасной вариант `type:objectId:sequence`
для доставки от старого ядра без него) — называет СОСТОЯНИЕ, которое несёт доставка: одинаков для
всех повторов и переотправок (переотправка повышает `sequence`, но `event_id` не меняет); храните обработанные и пропускайте
повторы. Заголовки `X-Webhook-Id` / `X-Webhook-Event-Id` / `X-Webhook-Event` / `X-Webhook-Test`
**не подписаны** — при повторе в них может стоять что угодно, — поэтому они отдаются только в
`$delivery->unverified` и никогда не должны решать, обрабатывать ли доставку; `$delivery->isTest`
берётся только из подписанного тела, а репетицию всегда подтверждайте и пропускайте.
`Verifier::isStale($delivery->event, $lastSequence)` отсеивает доставки не по порядку. После `webhooks->rotateSecret()` передавайте
`previousSecret:` не меньше 26 часов. Пустой `secret` — это `ConfigException`, а не проверка против
`HMAC('', body)`; окно свежести — 300 секунд (`toleranceSec: 0` его отключает) и проверяется после
MAC, поэтому по нему нельзя прощупать ваши часы. См.
[`examples/webhook-receiver.php`](examples/webhook-receiver.php).

## Ошибки

Любой отказ — это `Oblodai\Exception\OblodaiException`, сообщение которого читается в строке журнала
— `[payout.insufficient_funds] insufficient balance (request_id=5f0c…)` — и который несёт конверт
ошибки API: `errorCode`, `detail` (сам текст), `httpStatus`, `retryable`, `retryAfter`,
`requestId`, `field`, `synthetic` (ответил прокси, а не API). Называйте `requestId` поддержке;
`json_encode($err)` сохраняет сообщение и классификацию и отбрасывает сырое тело.

| класс                                             | HTTP        | когда                                           |
| ------------------------------------------------- | ----------- | ----------------------------------------------- |
| `ValidationException`                             | 400         | неверное тело запроса (`field` — где)           |
| `AuthenticationException`                         | 401         | неверная подпись, неизвестный ключ, старая метка |
| `PermissionException`                             | 403         | ключ верный, но вызов не разрешён               |
| `NotFoundException`                               | 404         | такого объекта нет                              |
| `ConflictException` / `IdempotencyConflictException` | 409       | конфликт состояния; ключ с другим телом         |
| `RateLimitException`                              | 429         | ограничение частоты — `retryAfter` скажет, сколько ждать |
| `UnavailableException`                            | 503         | шлюз занят или заморожен; можно повторить       |
| `InternalException`                               | прочие 5xx  | сбой шлюза                                      |
| `TransportException`                              | —           | ответа нет (таймаут, сеть, дедлайн)             |
| `ConfigException`                                 | —           | отказано до отправки                            |
| `ContractException`                               | —           | нечитаемый конверт или тело вебхука             |
| `SignatureException`                              | —           | проверка вебхука не прошла                      |

`retryable` — последнее слово: SDK уже повторил всё, что следовало. Ветвитесь по `errorCode` —
`семейство.причина`; коды, которыми метод может ответить, перечислены в его докблоке, полный
каталог — сгенерированный enum `Oblodai\Generated\Enum\ErrorCode`.

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

Собственные коды SDK никогда не приходят от API: `sdk.missing_credentials`, `sdk.bad_config`,
`sdk.bad_header`, `sdk.bad_path_param`, `sdk.bad_amount`, `sdk.float_amount`,
`sdk.bad_idempotency_key`, `sdk.idempotency_unsupported`, `sdk.lro_unresolved` (все —
`ConfigException`), `sdk.bad_envelope` и `webhook.bad_payload` (`ContractException`),
`sdk.response_too_large`, `transport.timeout`, `transport.network`, `transport.deadline`
(`TransportException`).

## Повторы, идемпотентность и таймауты

- **Можно ли повторить** — ответ самого контракта (`x-retry-safe`), а не догадка по пути: он есть у
  каждого сгенерированного маршрута (`Oblodai\Generated\Routes`).
- Ошибка повторяется, только если API ответил `retryable: true`. Ответы без конверта API (прокси
  502/503) и сбои транспорта повторяются только на безопасных маршрутах или записях с ключом —
  запись, которую шлюз не дедуплицирует, не отправляется повторно, если она могла дойти.
- **Ключи идемпотентности** создаются сами на маршрутах, которые шлюз дедуплицирует (один на вызов,
  тот же на каждом повторе), — таймаут не превратится во вторую выплату. Передайте свой ключ, чтобы
  быть защищённым и между перезапусками. На маршруте без дедупликации SDK ключ отклоняет
  (`sdk.idempotency_unsupported`); ключ с другим телом — 409 `idempotency.key_reused`.
- **Идентификаторы запросов:** каждый вызов отправляет свой `X-Request-ID` (один на все его
  повторы) — передайте `requestId`, чтобы связать ваши журналы с нашими. Ошибка несёт его, если
  конверт шлюза не назвал свой.
- **Опции вызова:** `new RequestOptions(idempotencyKey: …, timeout: …, maxRetries: …,
  extraHeaders: […], requestId: …)`. `timeout` — секунды на попытку; `extraHeaders` накладываются на
  заголовки клиента без учёта регистра; ничего подписанного SDK отсюда не переопределить.
- **Политика:** `retry: new Retry(maxRetries: 2, baseDelayMs: 250, maxDelayMs: 4000,
  maxRetryAfterMs: 30000)` — значения по умолчанию. `timeout` (30 с) ограничивает одну попытку,
  `deadline` (90 с) — весь вызов с паузами. `Retry-After` всегда важнее расчётной паузы.
- **Расхождение часов** исправляется один раз за вызов: если шлюз отверг метку времени, SDK узнаёт
  время сервера из заголовка `Date`, переподписывает запрос и сохраняет поправку для следующих.
- **Перенаправления не выполняются** — подпись покрывает запрошенный путь, — а тело ответа читается
  с потолком: 8 МиБ на JSON-маршрутах, 64 МиБ на документах.

```php
$quick = $oblodai->payments->getInfo(
    ['uuid' => $invoice->uuid],
    new RequestOptions(timeout: 5, maxRetries: 0, requestId: 'checkout-7-status'),
);
```

## Сырой ответ, копия клиента и хуки

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

Статус ошибки бросается и через `withRawResponse()`. Хук, бросивший исключение, останавливает вызов.

## Конфигурация

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

| опция                  | по умолчанию                | что делает                                                       |
| ---------------------- | --------------------------- | ---------------------------------------------------------------- |
| `publicId` / `secret`  | окружение                   | API-ключ; секрет только подписывает                              |
| `baseUrl`              | `https://api.oblodai.com`   | адрес API; префикс пути сохраняется                              |
| `http`                 | `CurlHttpClient`            | свой HTTP-стек — см. `Psr18HttpClient`                           |
| `timeout`              | `30`                        | таймаут одной попытки, секунды                                   |
| `deadline`             | `90`                        | общий бюджет вызова с повторами и паузами, секунды               |
| `retry`                | `new Retry()`               | политика повторов; `new Retry(maxRetries: 0)` их отключает       |
| `logger`               | нет                         | структурный логгер; `OBLODAI_LOG` включает консольный            |
| `headers`              | `[]`                        | дополнительные заголовки каждого запроса                         |
| `hooks`                | нет                         | `new Hooks(onRequest: …, onResponse: …)`                         |
| `adminToken`           | —                           | устарел и игнорируется (не отправляется); пишется warning        |
| `allowInsecureBaseUrl` | `false`                     | разрешить `baseUrl` по http (и на петлевом адресе)               |
| `clock`, `env`         | настоящие часы и окружение  | подменяются в тестах                                             |

| переменная                  | что задаёт                                                          |
| --------------------------- | ------------------------------------------------------------------- |
| `OBLODAI_PUBLIC_ID`         | публичный id API-ключа                                              |
| `OBLODAI_SECRET`            | секрет API-ключа                                                    |
| `OBLODAI_ADMIN_TOKEN`       | устарел и игнорируется (не отправляется)                            |
| `OBLODAI_BASE_URL`          | адрес API, по умолчанию `https://api.oblodai.com`; префикс сохраняется |
| `OBLODAI_LOG`               | `debug`\|`info`\|`warn`\|`error` — журнал в STDERR                 |
| `OBLODAI_ALLOW_INSECURE`    | `1` разрешает `baseUrl` по http (и на петлевом адресе)              |

Пустое значение считается незаданным. Явные аргументы конструктора всегда важнее окружения, а
`env: []` в конструкторе его полностью игнорирует (так делают тесты).

**Секреты не попадают в журнал.** Учётные данные не хранят значение на самом объекте,
поэтому `print_r`/`var_dump`/`json_encode`/`serialize` клиента, его конфигурации и транспорта
показывают `[redacted]`. Модели с одноразовым секретом (`secret` вебхука, `claim_token`, `claim_url`
и `passcode` выплатной ссылки) маскируют его в `json_encode`, `var_dump` и `serialize`, при этом
свойство читается, а `toArray()` возвращает его как есть — но `print_r`/`var_export` читают
публичные свойства напрямую, поэтому такую модель не печатайте через `print_r`. Переданный вами
логгер оборачивается, так что маскирование происходит до того, как SDK что-либо ему отдаст. Каждый
параметр с секретом помечен `#[\SensitiveParameter]`, так что трассировки исключений (и трекеры
ошибок, которые их читают) показывают `SensitiveParameterValue`. Хуки и сообщения об ошибках видят
токены чеков в пути и `sig`/`exp` подписанных ссылок замаскированными, а заголовки с ключами — скрытыми.

### HTTP-стек

По умолчанию — cURL. Чтобы использовать свой клиент, оберните любую реализацию PSR-18:

```php
use Oblodai\Http\Psr18HttpClient;

$oblodai = new Oblodai(
    publicId: $publicId,
    secret: $secret,
    http: new Psr18HttpClient($client, $requestFactory, $streamFactory),
);
```

PSR-18 описывает лишь «отправь запрос — получи ответ», поэтому три вещи настраиваются на самом
клиенте: **без перенаправлений** (`allow_redirects: false` в Guzzle, `max_redirects: 0` в Symfony),
**таймауты** (соединения и общий — `timeout` SDK через PSR-18 не применить, только общий дедлайн
вызова) и **включённая проверка TLS**. `CurlHttpClient` соблюдает всё это сам.

### Свой или локальный шлюз

`baseUrl` по http — и `http://localhost:8093` тоже — требует `allowInsecureBaseUrl: true` (или
`OBLODAI_ALLOW_INSECURE=1`). Префикс пути в `baseUrl` сохраняется. Маршрут оператора
`sandbox->onboardStore($merchantId)` SDK не поддерживает (ядро принимает его только по каналу
оператора): он бросает `sdk.operator_channel_unsupported` до любого запроса.

## Сгенерированный код

`src/Generated` (`Oblodai\Generated\…` — ресурсы, модели, enum'ы, таблица маршрутов и `Facts`:
долгие операции, виды вебхуков, неденежные числа) генерирует
`tools/sdkgen` шлюза из его OpenAPI-контракта, руками он не правится; рукописный runtime
(`Oblodai\Core`, `Oblodai\Exception`, `Oblodai\Webhook`, `Oblodai\Http`) подписывает, отправляет,
повторяет и разбирает. `names.lock` фиксирует каждое публичное имя метода: пропажа или смена имени
роняет генератор как ломающее изменение, а новое генератор сам дописывает в lock, как и таблицу
методов выше. `make drift` перегенерирует во временный каталог и падает, если отличается
`src/Generated`, `names.lock` или эта таблица.

## Разработка

```bash
make ci                                          # vendor, drift, lint, stan (level max), test, package
make ci OBLODAI_BACKEND=/path/to/oblodai-backend # клон бэкенда (по умолчанию ../oblodai-backend)
make live OBLODAI_LIVE_URL=http://127.0.0.1:8095 # живой уровень против работающего шлюза
```

Всё идёт в docker (`php:8.3-cli`, php-cs-fixer — на `php:8.2-cli`, `composer:2`); проверке дрейфа нужен Go для генератора бэкенда.
Тесты — unit (runtime), contract (сгенерированный код, README, примеры) и общий набор conformance
всех SDK Oblodai (`tools/sdkgen/conformance` бэкенда: векторы подписи, повторы, ключи
идемпотентности, деньги, совместимость вперёд). Живой уровень пропускается, пока `OBLODAI_LIVE_URL`
не указывает на работающий шлюз; он сам заводит мерчанта и тратит только тестовые деньги.

Пишете код с ИИ-агентом? Дайте ему [AGENTS.md](AGENTS.md). Переход с 1.x — см.
[MIGRATION-2.0.md](MIGRATION-2.0.md); история выпусков — в [CHANGELOG.md](CHANGELOG.md).

## Лицензия

MIT — см. [LICENSE](LICENSE).
