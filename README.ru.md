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

PHP ≥ 8.1 с `ext-json` и `ext-curl`, PSR-4 и `declare(strict_types=1)` везде, readonly-объект
значения на каждое тело ответа и никаких других зависимостей в рантайме, кроме PSR-интерфейсов HTTP:
из коробки работает cURL, но его место может занять любой PSR-18-клиент.

> **Базовый URL.** По умолчанию `https://api.oblodai.com`. При необходимости укажите свой `baseUrl` и
> свои ключи при инициализации. Схема должна быть `https://`; обычный `http://` принимается только
> для петлевого адреса (`http://127.0.0.1:8095`) или с явной опцией разрешения незащищённого
> соединения (`allowInsecureBaseUrl: true` либо `OBLODAI_ALLOW_INSECURE=1`).

## Установка

```bash
composer require oblodai/sdk
```

PHP 8.1 или новее с `ext-json` и `ext-curl`. Composer подтянет PSR-интерфейсы HTTP
(`psr/http-client`, `psr/http-factory`, `psr/http-message`); `psr/log` необязателен и нужен только
для того, чтобы направить лог SDK в Monolog или другой PSR-3-логгер.

## Где взять ключи

У мерчанта **один** API-ключ, он выпускается в кабинете
[my.oblodai.com](https://my.oblodai.com) → **API keys**. Это публичный идентификатор и секрет;
секрет только подписывает запрос и никогда не передаётся.

| ключ                    | публичный id          | секрет                | что открывает                                                                              |
| ----------------------- | --------------------- | --------------------- | -------------------------------------------------------------------------------------------- |
| **боевой API-ключ**     | `oblodai_<hex>`       | `oblodai_live_<hex>`  | весь мерчантский API: приём денег, вывод, настройки, документы                                 |
| **ключ песочницы**      | `test_oblodai_<hex>`  | `oblodai_test_<hex>`  | тот же API, но в песочнице; выдаётся онбордингом песочницы                                     |
| **админ-токен**         | —                     | —                     | провижининг на **self-hosted** шлюзе: `merchants->create()`, `merchants->createSandbox()`      |

Эта одна пара подписывает все маршруты, которые шлюз закрывает подписью, — выбирать нечего:

```php
use Oblodai\Oblodai;

$oblodai = new Oblodai(publicId: $publicId, secret: $secret);
```

Админ-токен вообще не мерчантский ключ: он уходит в заголовке `X-Admin-Token` только на двух
онбординговых маршрутах, и есть он лишь у шлюза, который вы разворачиваете сами.

У аккаунтов, открытых до перехода на единый ключ, может остаться **старая раздельная пара**:
платёжный ключ `oblodai_pk_…` и выплатной `oblodai_wk_…`. Каждая половина по-прежнему работает на
своей половине API, поэтому на каждый ключ заводите свой клиент; и только такая пара может увидеть
403 `merchant.wrong_key_kind` (выплатной маршрут подписан платёжным ключом или наоборот). Все ключи,
которые выпускаются сейчас, — один `oblodai_…`.

## Быстрый старт

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

Чтобы выставлять цену в фиате, добавьте `to_currency`: `['amount' => '25', 'currency' => 'USD',
'to_currency' => 'USDT']` — `currency` это то, что вы списываете, а `to_currency` — актив, который
присылает плательщик.

Вывод денег устроен так же и подписывается тем же ключом, только со своим ключом идемпотентности,
чтобы повтор после перезапуска не отправил деньги дважды:

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

Телом любого запроса может быть и сгенерированный DTO — именно в нём живёт документация полей,
которую редактор показывает при наведении:

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

Запускаемые версии всего этого лежат в [`examples/`](examples).

## Песочница и тестирование

Ключ песочницы (`test_oblodai_…`) управляет копией шлюза без блокчейна: фейковый баланс из крана,
симулированные депозиты, настоящие подписанные вебхуки. Интегрируйтесь сначала на ней — ничего из
этого не касается сети.

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

Репетиционную доставку можно запросить и на бою: `webhooks->test(WebhookKind::Payment,
['url_callback' => …, 'status' => 'paid'])` отправит образец события, подписанный ровно как
настоящий, с `test: true` в теле. Никогда не позволяйте такому событию двигать деньги в вашей
системе — см. [Вебхуки](#вебхуки).

На боевом ключе песочные помощники отвечают 403 `sandbox.live_key`.

## Обзор методов

Шестнадцать пространств имён, 107 маршрутов — все мерчантские маршруты, которые есть у шлюза.

| пространство    | методы                                                                                                                                                                                    | маршруты                                                        |
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

Последним аргументом каждый метод принимает необязательный
`new RequestOptions(idempotencyKey: …, timeoutMs: …, deadlineMs: …, headers: […])`.
Поиск объекта принимает как голый uuid, так и массив: `$oblodai->payments->info('uuid')`,
`$oblodai->payments->info(['order_id' => '…'])`.

### Списки

Постраничные методы возвращают `Oblodai\Core\Page`: первая страница — через `items()`/`paginate()`,
все страницы — итерацией по объекту. Пока вы его не потребляете, ни одного запроса не уходит.
Обход останавливается, когда сервер сказал `has_pages: false` либо вернул неполную страницу —
смотря что случится раньше.

```php
$page = $oblodai->payments->history(['limit' => 50]);
$page->items();                       // list<Payment>
$page->paginate()->total;             // total, per_page, offset, has_pages

foreach ($oblodai->payouts->history(['status' => 'confirmed']) as $payout) {
    echo $payout->uuid, "\n";         // walks page after page, lazily
}

$refunds = $oblodai->payouts->history(['kind' => 'refund'])->all(1000);
```

Несколько маршрутов не постраничные и возвращают обычный `array`: `settings->listAutoWithdraw()`,
`settings->setAutoWithdraw()`, `settings->deleteAutoWithdraw()`, методы `*ApiAllowlist()`, а также
синхронные батчи `payouts->mass()` (≤100) и `payoutLinks->batch()` (≤500), которые дают
`list<BatchElement>` с исходом по каждому элементу. `payments->batch()`, `payouts->batch()`,
`refunds->batch()` и `transfers->batch()` асинхронные (≤5000) и возвращают талон `BatchSubmitted`,
который опрашивают через `batches->info()`.

### Статусы

- Платёж: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
  `Status::isPaymentPaid()` истинно для `paid`/`paid_over`; `wrong_amount` (недоплата) ждёт
  `refunds->resolve(['uuid' => …, 'action' => 'accept'|'refund'])`; `Status::isPaymentFinal()`
  покрывает остальное.
- Выплата: `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.

О смене состояния лучше узнавать из вебхуков; `info()` опрашивать только как запасной путь.

Статусы (и прочие закрытые словари) разбираются в открытое значение
`Oblodai\Contract\Model\OpenEnum`:

```php
$payment->status->value;                     // "paid" — always the raw wire string
$payment->status->is(PaymentStatus::Paid);   // true
$payment->status->known;                     // PaymentStatus::Paid, or null if newer than this SDK
$payment->status->isKnown();                 // false → log it and move on
```

Значение, которого нет в поставленном снимке, никогда не бросает исключение. Шлюз добавляет статусы
по своему графику, а получатель вебхуков, который отверг бы первый незнакомый, ответил бы 500 на
подлинную доставку и получал бы её повторно целые сутки. `Status::isPaymentPaid()` и соседи просто
отвечают false на статус, которого не знают. Чтобы расхождение было слышно в тестах, вызовите
`Oblodai\Contract\Model\Wire::strict()`.

Открытые словари, которые шлюз расширяет регулярно, — `network`, `kind`, `fee_type`, `source` —
остаются обычными строками, а каждая модель хранит нетронутое тело ответа в `->raw`.

### Деньги

`Oblodai\Helper\Money::add()`, `subtract()`, `compare()`, `equals()`, `isZero()`, `isPositive()`,
`assertAmount()` — точная десятичная арифметика над строковыми суммами, которыми оперирует API.
Никогда не приводите денежное поле к `float` и не сравнивайте суммы как строки (`"9"` как текст
идёт после `"10"`, а как деньги — раньше; используйте `compare()`). Всё, что не является десятичной
суммой длиной не больше 64 символов, — это `ConfigException` (`sdk.bad_amount`); float в теле
запроса SDK отвергает сразу.

## Вебхуки

Зарегистрируйте адрес через `webhooks->register($url)` — секрет подписи возвращается один раз,
сохраните его сразу. Проверяйте каждую доставку по **сырым** байтам запроса: пересериализованный
разбор не совпадёт.

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

`Verifier` не нужен ни клиент, ни API-ключ. Отвечайте на каждый вид отказа своим кодом:

| исключение                  | что произошло                                  | ответ                 |
| --------------------------- | ---------------------------------------------- | --------------------- |
| `ConfigException`           | получатель настроен неправильно (нет секрета)  | 500 и почините        |
| `SignatureException`        | доставка не наша или слишком старая            | 401                   |
| `WebhookPayloadException`   | доставка наша, тело нечитаемо                  | 2xx (или 400) + алерт |

`webhook.bad_payload` намеренно НЕ относится к семейству подписи: MAC уже доказал подлинность
события, а ответ 401 заставил бы шлюз пересылать его сутки. **401 — только для отказа подписи и ни
для чего больше.** Тип события, которого этот SDK не моделирует, тоже не отказ: он приходит как
`UnknownEvent` с нетронутым телом, а `Verifier::isKnownEvent($event)` говорит, что именно у вас в
руках.

Репетиционные доставки (`webhooks->test()`, песочница) подписаны ровно как настоящие и несут
`test: true` в теле (и `X-Webhook-Test: true`) — проверяйте `$delivery->isTest` (или
`Verifier::isTestEvent($event)`) и никогда не реагируйте на них так, будто деньги двинулись.
`$delivery->id` (`X-Webhook-Id`) не меняется между повторами — дедуплицируйте по нему;
`$event->sequence()` упорядочивает события (`Verifier::isStale()`, который ложен для события без
sequence). После `webhooks->rotateSecret()` передавайте `previousSecret:` минимум 26 часов — до
`previous_secret_valid_until`, который вернула ротация.

Секрет проверяется до любой криптографии: пустой `secret` (как и пустой `previousSecret` или
отрицательный `toleranceSec`) — это `ConfigException`, а не проверка против `HMAC('', body)`. Окно
свежести по умолчанию 300 секунд; `toleranceSec: 0` его отключает. Подпись сверяется РАНЬШЕ метки
времени, поэтому через окно нельзя прощупать ваши часы.

## Ошибки

Любой отказ — это `Oblodai\Exception\OblodaiException` с конвертом ошибки от API: `errorCode`
(`payout.insufficient_funds`), `httpStatus`, `retryable`, `retryAfter`, `requestId`, `field`,
`synthetic` (ответ пришёл от прокси, а не от API). `requestId` называйте в поддержке;
`json_encode($err)` сохраняет сообщение и классификацию и выбрасывает сырое тело.

| класс                                             | HTTP        | когда                                           |
| ------------------------------------------------- | ----------- | ----------------------------------------------- |
| `ValidationException`                             | 400         | тело запроса неверно (`field` укажет, где)      |
| `AuthenticationException`                         | 401         | плохая подпись, неизвестный ключ, старая метка  |
| `PermissionException`                             | 403         | ключ верный, но вызов не разрешён               |
| `NotFoundException`                               | 404         | объекта нет                                     |
| `ConflictException` / `IdempotencyConflictException` | 409       | конфликт состояния; ключ повторён с другим телом |
| `RateLimitException`                              | 429         | троттлинг — `retryAfter` скажет, сколько ждать  |
| `UnavailableException`                            | 503         | шлюз занят или заморожен; повторяемо            |
| `InternalException`                               | прочие 5xx  | сбой шлюза                                      |
| `TransportException`                              | —           | ответа нет вовсе (таймаут, сеть, дедлайн)       |
| `ConfigException`                                 | —           | отклонено до того, как что-либо ушло            |
| `ContractException`                               | —           | нечитаемый конверт или тело вебхука             |
| `SignatureException`                              | —           | проверка подписи вебхука не прошла              |

`retryable` авторитетен: SDK уже повторил всё, что был должен, поэтому дошедшая до вас
`retryable`-ошибка — та, которую повтор в принципе может вылечить, но попытки или бюджет вызова
закончились. Ветвитесь по `errorCode` (`family.reason`): полный каталог из 469 кодов лежит в
`Oblodai\Contract\Enums::ERROR_CODES`, а коды, которые стоит обработать, названы в докблоке каждого
метода, двигающего деньги:

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

Собственные коды SDK никогда не приходят от API — они поднимаются до ответа или вместо него:
`sdk.missing_credentials`, `sdk.bad_config`, `sdk.bad_header`, `sdk.bad_path_param`,
`sdk.bad_amount`, `sdk.bad_idempotency_key`, `sdk.idempotency_unsupported` (все — `ConfigException`),
`sdk.bad_envelope` и `webhook.bad_payload` (`ContractException`), `sdk.response_too_large`,
`transport.timeout`, `transport.network`, `transport.deadline` (`TransportException`).

## Повторы, идемпотентность и таймауты

- **Безопасность повтора** — ответ самого шлюза, а не догадка по пути: у каждого маршрута в
  контракте есть проставленный вручную флаг `safe` (`Oblodai\Contract\Routes::SPECS`). SDK его
  никогда не выводит сам.
- Ошибка повторяется только тогда, когда API сказал `retryable: true`. Ответы без конверта API
  (502/503 от прокси) и транспортные сбои повторяются только на читающих маршрутах и на записях с
  ключом идемпотентности: запись, которую шлюз не дедуплицирует, не отправляется повторно, если она
  могла дойти.
- **Ключи идемпотентности** генерируются автоматически на создающих маршрутах (один на логический
  вызов, тот же самый на каждом повторе), поэтому таймаут не может породить вторую выплату. Передайте
  свой ключ, чтобы повтор был безопасен и после перезапуска процесса. На маршруте, который шлюз не
  дедуплицирует, SDK отказывается принять ключ (`sdk.idempotency_unsupported`); ключ, повторённый с
  другим телом, — это 409 `idempotency.key_reused`. Страницы списков ключ вызывающей стороны не
  несут никогда.
- **Опции вызова:** `new RequestOptions(idempotencyKey: …, timeoutMs: …, deadlineMs: …,
  headers: […])`. Заголовки вызова накладываются на клиентские без учёта регистра; ничего из того,
  что SDK подписывает, оттуда переопределить нельзя.
- **Политика:** `retry: new Retry(maxRetries: 2, baseDelayMs: 250, maxDelayMs: 4000, maxRetryAfterMs:
  30000)` — это значения по умолчанию; `new Retry(maxRetries: 0)` отключает повторы. `timeoutMs`
  (по умолчанию 30000) ограничивает одну попытку, `deadlineMs` (по умолчанию 90000) — весь вызов
  вместе с паузами. `Retry-After` всегда важнее вычисленной задержки; `$err->retryAfter` показывает,
  сколько попросил шлюз (с потолком в сутки), а собственный сон SDK ограничен `maxRetryAfterMs`.
- **Расхождение часов** правится один раз за вызов: если шлюз отверг метку времени, SDK узнаёт время
  сервера из заголовка `Date` и переподписывает запрос, а затем сохраняет смещение для следующих
  вызовов.
- **Редиректы не выполняются никогда** — подпись покрывает тот путь, который был запрошен, — а тело
  ответа читается с потолком: 8 MiB на JSON-маршрутах и 64 MiB на документных, выше которого вызов
  падает с `sdk.response_too_large`.

## Конфигурация

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

| опция                  | по умолчанию                | что делает                                                       |
| ---------------------- | --------------------------- | ---------------------------------------------------------------- |
| `publicId` / `secret`  | окружение                   | API-ключ; секрет только подписывает                              |
| `baseUrl`              | `https://api.oblodai.com`   | адрес API; префикс пути сохраняется                              |
| `http`                 | `CurlHttpClient`            | свой HTTP-стек — см. `Psr18HttpClient`                           |
| `timeoutMs`            | `30000`                     | таймаут одной попытки                                            |
| `deadlineMs`           | `90000`                     | общий бюджет вызова, включая повторы и паузы                     |
| `retry`                | `new Retry()`               | политика повторов; `new Retry(maxRetries: 0)` выключает их       |
| `logger`               | нет                         | структурный логгер; `OBLODAI_LOG` включает консольный            |
| `headers`              | `[]`                        | дополнительные заголовки на каждом запросе                       |
| `adminToken`           | окружение                   | админ-токен self-hosted шлюза (онбординговые маршруты)           |
| `allowInsecureBaseUrl` | `false`                     | разрешает `baseUrl` по http вне петлевого адреса                 |
| `clock`, `env`         | реальные часы и окружение   | подменяемые, для тестов                                          |

| переменная                  | что задаёт                                                          |
| --------------------------- | ------------------------------------------------------------------- |
| `OBLODAI_PUBLIC_ID`         | публичный id API-ключа                                              |
| `OBLODAI_SECRET`            | секрет API-ключа                                                    |
| `OBLODAI_ADMIN_TOKEN`       | админ-токен для провижининговых маршрутов self-hosted шлюза         |
| `OBLODAI_BASE_URL`          | адрес API, по умолчанию `https://api.oblodai.com`; префикс пути сохраняется |
| `OBLODAI_LOG`               | `debug`\|`info`\|`warn`\|`error` — лог в STDERR                      |
| `OBLODAI_ALLOW_INSECURE`    | `1` разрешает `baseUrl` по http вне петлевого адреса                |

Пустое значение считается незаданным. Явные аргументы конструктора всегда важнее окружения, а
`env: []` в конструкторе игнорирует его целиком (так делает набор тестов).

**Секреты не попадают в лог.** Учётные данные и админ-токен держат своё значение вне самого объекта,
поэтому `print_r`/`var_dump`/`json_encode`/`serialize` клиента, его конфигурации или транспорта
показывают `[redacted]`; модели, которые несут одноразовый секрет (`WebhookEndpoint`,
`WebhookSecretRotated`, `ApiKeyPair`, `MerchantOnboarded`, `PayoutLink` — `claim_token`, `claim_url`
и `passcode`, причём URL потому, что в него вшит токен), маскируют его при любом сплошном выводе,
оставляя само свойство читаемым. Любой переданный вами логгер оборачивается, так что маскирование
происходит до того, как SDK что-либо ему отдаст.

### HTTP-стек

По умолчанию используется cURL. Чтобы переиспользовать свой клиент, оберните любую реализацию
PSR-18:

```php
use Oblodai\Http\Psr18HttpClient;

$oblodai = new Oblodai(
    publicId: $pk,
    secret: $sk,
    http: new Psr18HttpClient($client, $requestFactory, $streamFactory),
);
```

PSR-18 описывает только «отправь запрос, получи ответ», поэтому три вещи нужно настроить на самом
клиенте:

- **никаких редиректов** (`allow_redirects: false` в Guzzle, `max_redirects: 0` в Symfony) — подпись
  покрывает запрошенный путь, а PSR-18 не даёт SDK узнать, какой URL ответил на самом деле;
- **таймауты**, на соединение и общий, — `timeoutMs` из SDK сюда применить нельзя, остаётся только
  общий дедлайн вызова;
- **проверка TLS оставлена включённой**.

`CurlHttpClient` (клиент по умолчанию) сам обеспечивает все три пункта, и переубедить его через
`$curlOptions` нельзя.

### Свой или локальный шлюз

`baseUrl: 'http://localhost:8093'` работает сразу; любому другому http-хосту нужен
`allowInsecureBaseUrl: true` (или `OBLODAI_ALLOW_INSECURE=1`). Префикс пути в `baseUrl`
сохраняется. Провижининговым маршрутам `merchants->create()` и `merchants->createSandbox()` нужен
`adminToken:` шлюза (или `OBLODAI_ADMIN_TOKEN`), который уходит в заголовке `X-Admin-Token` только
на них.

## Снимок контракта

`contract/` выгружает собственный набор тестов шлюза: реестр маршрутов (107 мерчантских маршрутов, у
каждого — свой шлюз авторизации: `public`, `key` или `onboard`, обёртка идемпотентности и
проставленный вручную флаг `safe`), схемы
DTO запросов с английской документацией полей, перечисления, все коды ошибок (469), векторы подписи,
эталонные тела ответов, записанные с живого шлюза, и настоящие подписанные доставки вебхуков.

`src/Contract/{Routes,Enums,Version}.php`, `src/Contract/Enum/*` и `src/Contract/Request/*`
генерируются из него командой `composer codegen`; `composer check-drift` падает, когда они
расходятся, а контрактный ярус тестов сверяет каждую модель с эталонными телами. Какой снимок несёт
релиз, видно в `Oblodai\Contract\Version` — `CORE_COMMIT`, `EXPORTED_AT`, `CONTRACT_HASH`; этот
собран на ядре `2cc44c16`. Чтобы обновить: положите новую выгрузку в `contract/`, выполните
`composer codegen`, затем `composer ci`.

## Разработка

```bash
composer install
composer ci          # check-drift + lint + stan (level max) + test (unit + contract)
composer fmt         # apply the formatting `composer lint` checks
composer codegen     # after refreshing contract/
OBLODAI_LIVE_URL=http://127.0.0.1:8095 composer test-live   # the journey against a real gateway
```

Живой ярус пропускается, пока `OBLODAI_LIVE_URL` не указывает на запущенный шлюз; он сам заводит
себе мерчанта, выпускает ключ `test_oblodai_…` и тратит только фейковые деньги.

Пишете код вместе с ИИ-агентом? Дайте ему [AGENTS.md](AGENTS.md). Обновляетесь с 1.2, которая
подписывала запросы так, как шлюз больше не принимает, — читайте
[MIGRATION-1.3.md](MIGRATION-1.3.md); история релизов лежит в [CHANGELOG.md](CHANGELOG.md).

## Лицензия

MIT — см. [LICENSE](LICENSE).
