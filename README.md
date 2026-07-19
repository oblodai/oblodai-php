# Oblodai PHP SDK

Официальный PHP SDK для платёжного шлюза **Oblodai**: приём платежей, выплаты, статические кошельки,
вебхуки. Один API-ключ — весь функционал. Без внешних зависимостей (cURL), с инъектируемым транспортом.

## Установка

```bash
composer require oblodai/sdk
```

Требования: PHP 8.0+, расширения `json` и `curl`.

## Учётные данные

Храните ключи в переменных окружения (см. `.env.example`) — секрет **только на сервере**, никогда в браузере:

```bash
export OBLODAI_PUBLIC_ID=oblodai_...
export OBLODAI_SECRET=oblodai_live_...
# необязательно: export OBLODAI_BASE_URL=https://api.oblodai.com
```

```php
use Oblodai\Client;

$client = Client::fromEnv(); // OBLODAI_PUBLIC_ID / OBLODAI_SECRET / OBLODAI_BASE_URL
```

## Быстрый старт

```php
use Oblodai\Client;

// либо явно (эквивалент fromEnv выше):
$client = new Client($publicId, $secret, ['base_url' => 'https://api.oblodai.com']);

$payment = $client->payments()->create([
    'amount'      => '10',
    'currency'    => 'USD',
    'order_id'    => 'order-1',
    'to_currency' => 'USDT',
    'network'     => 'tron',
    'url_callback' => 'https://your-shop.example/oblodai/webhook',
]);

echo $payment['address']; // адрес для оплаты
echo $payment['url'];     // hosted-страница оплаты
```

## Проверка вебхуков

Oblodai подписывает каждую доставку секретом, который вернул `POST /v1/webhooks` (зарегистрируйте
endpoint один раз и сохраните секрет). Проверяйте входящие вебхуки этим секретом:

```php
use Oblodai\Webhooks;
use Oblodai\Exception\SignatureException;

$raw = file_get_contents('php://input');

// Пробные вебхуки (is_test) не подписаны — просто подтверждаем.
if (Webhooks::isTest($raw)) { http_response_code(200); exit; }

try {
    $event = Webhooks::constructEvent(
        $webhookSecret,                          // из $client->webhooks()->register($url)['secret']
        $raw,
        $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '',
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''
    );
    // $event['uuid'], $event['status'], ...
    // Перепроверьте статус авторитетно:
    $info = $client->payments()->info($event['uuid']);
    // $info['payment_status']: check|confirm_check|paid|paid_over|wrong_amount|wrong_amount_waiting|cancel|select
} catch (SignatureException $e) {
    http_response_code(403);
}
```

## Ресурсы

- `payments()` — create, info, history, services, qr, refund, accepted, accuracy, autorefund, discount · **с v1.1.0:** createBatch, refundBatch, sendEmail, resolve · **с v1.2.0:** публичные publicGet/publicSelect (без подписи, для своих checkout-страниц)
- `payouts()` — create, createMass, info, history, services, calculate, approve, fee-config, refund · **с v1.1.0:** createBatch
- `batches()` **(v1.1.0)** — info (прогресс и результаты пачки)
- `links()` **(v1.1.0)** — платёжные ссылки: create, list, info, toggle, publicGet, checkout (алиас `paymentLinks()`)
- `payoutLinks()` **(v1.1.0)** — «крипто-чеки»: create, createBatch (до 500), list, info, cancel + публичные claimInfo/claim (без подписи)
- `splits()` **(v1.1.0)** — splitToAddress, splitToMerchant, createRule, listRules, deleteRule, getConfig, setConfig
- `wallets()` — create, block, blockedAddressRefund, qr
- `account()` — balance, referral, transferToPersonal, vrcs · **с v1.2.0:** transferToUser, transferBatch (переводы пользователям платформы)
- `webhooks()` — register, deliveries, testPayment/Wallet/Payout
- `settings()` — auto-withdraw, IP-allowlist
- `rates()` — list (курсы), currencies (каталог, публичный)
- `sandbox()` **(v1.2.0)** — только для тестовых ключей: simulateDeposit, faucet, reset, listWebhooks, replayWebhook

## Новое в v1.1.0 (коротко)

```php
// Пачки: до 5000 платежей/возвратов/выплат одним подписанным запросом.
$sub  = $client->payments()->createBatch([
    ['amount' => '10', 'currency' => 'USD', 'order_id' => 'a-1'],
    ['amount' => '20', 'currency' => 'EUR', 'order_id' => 'a-2'],
]); // 'continue' (по умолчанию) или 'stop' вторым аргументом
$info = $client->batches()->info($sub['batch_id'], 100, 0);

// Платёжная ссылка: платят многие, каждый платёж — свой инвойс.
$link = $client->links()->create(['amount_mode' => 'open', 'currency' => 'USD']);

// Сплит: доля каждого платежа автоматически уходит партнёру.
$client->splits()->splitToAddress('T...', 'tron', 10.0, 'партнёр А');

// Счёт на e-mail (письмо с кнопкой «Оплатить»).
$client->payments()->sendEmail($payment['uuid'], null, 'buyer@example.com');

// Судьба недоплаты: оставить себе или вернуть плательщику.
$client->payments()->resolve(['uuid' => $payment['uuid'], 'action' => 'accept']);

// Payout-ссылка («крипто-чек»): выплата без знания кошелька получателя.
// expires_in_hours задавайте ЯВНО: без него бэкенд клампит срок к 1 часу.
$check = $client->payoutLinks()->create([
    'currency' => 'USDT', 'network' => 'tron', 'amount' => '25',
    'reference' => 'bonus-42', 'expires_in_hours' => 168,
]);
// $check['claim_url'] отдаёте получателю; claim_token виден ТОЛЬКО в ответе create.

// Получатель (публично, без API-ключа):
$client->payoutLinks()->claimInfo($check['claim_token']);          // детали чека
$client->payoutLinks()->claim($check['claim_token'], 'T-адрес');   // забрать на свой кошелёк
```

## Новое в v1.2.0 (коротко)

```php
// Перевод пользователю платформы: внутренний, БЕЗ комиссии, с баланса мерчанта
// на личный кошелёк пользователя Oblodai. to_user_id — UUID пользователя (НЕ юзернейм).
// Ключ выплат; уходит с заголовком Idempotency-Key.
$res = $client->account()->transferToUser([
    'to_user_id' => 'a0b1c2d3-...-000000000001',
    'amount'     => '10',
    'currency'   => 'USDT',
    'order_id'   => 'tr-1', // необязателен
]);
// $res: {currency, amount, to_user_id, recipient_balance}

// Пачка переводов пользователям (фон): элементы — тела transferToUser().
$batch = $client->account()->transferBatch([
    ['to_user_id' => 'u1', 'amount' => '5', 'currency' => 'USDT', 'order_id' => 't-1'],
    ['to_user_id' => 'u2', 'amount' => '7', 'currency' => 'USDT', 'order_id' => 't-2'],
]); // 'continue' (по умолчанию) или 'stop' вторым аргументом
$info = $client->batches()->info($batch['batch_id']);

// Публичные эндпоинты счёта — для СОБСТВЕННЫХ checkout-страниц, без API-ключа
// на фронте (тот же механизм, что publicGet/claimInfo у ссылок).
$state = $client->payments()->publicGet($payment['uuid']);        // GET /v1/pay/{id}
$final = $client->payments()->publicSelect($payment['uuid'], 'USDT', 'tron'); // POST /v1/pay/{id}/select
// publicSelect финализирует отложенный (валюто-агностичный) счёт: покупатель
// выбирает валюту/сеть, ответ — обычный результат платежа (address, payment_status, ...).
```

## Песочница / тестирование (v1.2.0)

У Oblodai есть developer sandbox. **Бизнес-эндпоинты и код интеграции одинаковы для test и
live** — меняется только ключ: тестовый public id начинается с `test_…`, тестовый секрет —
с `oblodai_test_…`. Ничего перенастраивать не нужно: тот же `base_url`, те же методы SDK.

Новое — пять sandbox-хелперов `$client->sandbox()`, которые заменяют «покупатель заплатил
on-chain». Они существуют **только в песочнице**: вызов с live-ключом вернёт
403 `sandbox.live_key`. Это **ТОЛЬКО тестовый код** — не зовите их из продакшена
(удобный гард: `Client::isTestKey($publicId)`).

```php
use Oblodai\Client;

$client = Client::fromEnv(); // OBLODAI_PUBLIC_ID=test_..., OBLODAI_SECRET=oblodai_test_...

// 1. Обычный бизнес-вызов — тот же код, что и в проде.
$payment = $client->payments()->create([
    'amount' => '10', 'currency' => 'USD', 'order_id' => 'order-1',
    'to_currency' => 'USDT', 'network' => 'tron',
]);

// 2. «Оплатить» счёт: без amount платится ровно сумма счёта.
$client->sandbox()->simulateDeposit($payment['uuid']);

// 3. Дождаться статуса, как в проде (или принять вебхук).
$info = $client->payments()->info($payment['uuid']); // payment_status: paid

// 4. Баланс «из воздуха» — и любой платный вызов, например выплата.
$client->sandbox()->faucet('USDT', '100'); // не более 1000000 за вызов
$client->payouts()->create(['amount' => '5', 'currency' => 'USDT', 'address' => 'T...', 'order_id' => 'w-1']);

// Ещё: журнал доставок вебхуков (до 50, новые первыми) и повторная доставка.
$deliveries = $client->sandbox()->listWebhooks();
$client->sandbox()->replayWebhook($deliveries[0]['id']);

// Чистый лист: отменить открытые счета, обнулить балансы.
$client->sandbox()->reset();
```

Сценарии `simulateDeposit`:

- `['amount' => '5']` — недоплата, `['amount' => '15']` — переплата (без amount — ровно сумма счёта);
- `['confirmations' => 1, 'txid' => 't1']` — «зависший» депозит с малым числом подтверждений;
  повтор с **тем же** `txid` и бОльшим `confirmations` углубляет его (тот же `txid` = идемпотентность).

Нюансы:

- **счёт с недобором подтверждений сам НЕ дозревает.** Симулированный депозит никто не переэмитит
  и никакой курсор для него не двигается: счёт останется в `confirm_check` сколько угодно долго.
  Единственный способ довести его до `paid` — повторить `simulateDeposit` с **тем же** `txid` и
  бОльшим `confirmations`;
- **~10 минут — это про другое.** Речь о maturity-**холде на выплате** (ошибка
  `payout.funds_maturing`): свежепришедшие средства нельзя выводить сразу. В песочнице этот холд
  снимается по возрасту фоновым джобом — по умолчанию через 10 минут
  (`GATEWAY_SANDBOX_MATURITY_MINUTES` на стороне шлюза). К глубине подтверждений счёта это
  отношения не имеет: холд трогает только баланс. Не ждать 10 минут — тот же приём: повтор
  `simulateDeposit` с тем же `txid` и достаточно бОльшим `confirmations` доводит депозит до
  reorg-safe глубины и снимает холд сразу;
- в UTXO-сетях (Bitcoin и т.п.) нет авто-возврата переплаты и нет адреса плательщика —
  как и в проде (для возврата адрес задаётся явно).

## Обработка ошибок

```php
use Oblodai\Exception\ApiException;

try {
    $client->payouts()->create([...]);
} catch (ApiException $e) {
    $e->getErrorCode();   // "payout.insufficient_funds" — ветвитесь по коду
    $e->getStatusCode();  // HTTP-статус
    $e->isRetriable();    // временная ли ошибка
}
```

Клиент автоматически повторяет 5xx/429/сетевые сбои с экспоненциальным backoff (со случайным
джиттером) и учётом заголовка `Retry-After`. Отключить: `new Client($id, $secret, ['retry' => false])`.

## Идемпотентность (изменилось в v1.1.0)

Создающие вызовы (`payments()->create/refund/resolve`, все `createBatch`/`refundBatch`,
`payouts()->create/createMass`, `payoutLinks()->create/createBatch`,
`wallets()->blockedAddressRefund`, `account()->transferToPersonal/transferToUser/transferBatch`) уходят с HTTP-заголовком
**`Idempotency-Key`** (UUID v4). Ключ генерируется **один раз до цикла повторов** и одинаков во
всех внутренних ретраях, поэтому таймаут/обрыв сети не создаёт дубль. Заголовок не входит в
подпись и в тело запроса.

- **Ломающее изменение:** SDK больше **не подставляет** `order_id` автоматически — он уходит как
  есть. Если вы полагались на сгенерированный `idem-…`, задавайте `order_id` явно.
- Свой ключ: передайте `'idempotency_key' => '...'` в параметрах создающего вызова (или последним
  аргументом в `createBatch`) — он уйдёт в заголовок.
- `payoutLinks()->create/createBatch` **(с v1.2.0)** тоже шлют заголовок: они резервируют баланс,
  и раньше авто-ретрай по потерянному ответу мог профинансировать **вторую** ссылку.
  Повтор с тем же ключом реплеит **первый ответ целиком** — ту же ссылку и тот же `claim_token`, —
  и отвечает заголовком `Idempotent-Replayed: true`; баланс дебетуется ровно один раз.
  **Без** заголовка поведение прежнее: два одинаковых вызова создадут **две** ссылки.
- Второй, независимый рубеж для payout-ссылок — per-link `reference` (уникален в рамках мерчанта).
  Он работает и без заголовка, и там, где реплей ответа недоступен (см. про батчи ниже).
  Дубль `reference` — это `409 payoutlink.duplicate_reference` (раньше был `500`, то есть SDK его
  ретраил; теперь это терминальная ошибка и повтора не будет).
- **Батчи:** частично упавшая пачка реплеится **как есть** — упавшие элементы под тем же ключом
  не переобрабатываются, допинывайте их новым вызовом с **новым** ключом. И отдельно: ответ
  батча больше **256 КБ не кэшируется**, поэтому повтор с тем же ключом выполнится заново.
  На батчах поэтому обязательно проставляйте per-item `reference` — это единственный рубеж,
  который в таком случае остаётся.
- `wallets()->blockedAddressRefund` **(с v1.2.0)** тоже шлёт заголовок, но эндпоинт **намеренно не
  обёрнут** в серверную идемпотентность, и это не пробел: его защита серверная, безусловная и от
  заголовка не зависит. Бэкенд выводит стабильный `reference` из id кошелька, берёт per-wallet
  advisory lock и внутри лока сначала ищет уже существующую выплату — повтор (в том числе
  конкурентный, в том числе вовсе без заголовка) возвращает **первую** выплату, а не создаёт вторую.
  Оговорка: адрес в `reference` не входит, поэтому повтор с **другим** адресом вернёт первую
  выплату на **первый** адрес.
- `payouts()->approve` идемпотентности **не требует**: это переход состояния, сервер принимает
  только выплату в статусе `pending` и иначе отвечает `409 payout.not_pending`. Повторный approve
  физически не может одобрить или сдвинуть деньги дважды; читайте этот `409` как «уже одобрено»
  и уточняйте фактический статус через `payouts()->info()`.
- Для выплат `order_id` по-прежнему обязателен и задаётся вами.

### Коды ответов идемпотентности

Маршруты, обёрнутые в серверную идемпотентность (`payoutLinks()->create/createBatch` и остальные
денежные создающие вызовы), могут вернуть:

| Код | Ошибка | Ретраится SDK | Что значит |
| --- | --- | --- | --- |
| 400 | `idempotency.bad_key` | нет | ключ невалиден (например, длиннее 255 символов) |
| 400 | `idempotency.key_reused` | нет | тот же ключ прислан с **другим** телом |
| 409 | `idempotency.in_progress` | нет | первый запрос с этим ключом ещё выполняется — подождите и уточните результат |
| 409 | `payoutlink.duplicate_reference` | нет | `reference` уже занят (раньше отдавался как `500`) |
| 503 | `idempotency.unavailable` | **да** | стор идемпотентности недоступен, запрос отклонён fail-closed |

Классификация в SDK совпадает: `ApiException::isRetriable()` истинна только для 5xx и 429, так что
`400`/`409` терминальны (авто-повтор их не тронет), а `503 idempotency.unavailable` повторяется
автоматически с тем же ключом.

## Свой HTTP-транспорт

По умолчанию — cURL. Подставьте свой (Guzzle/PSR-18/мок), реализовав `Oblodai\Http\Transport`:

```php
$client = new Client($id, $secret, ['transport' => new MyTransport()]);
```

## Лицензия

MIT.
