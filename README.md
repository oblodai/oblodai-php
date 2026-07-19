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

- `payments()` — create, info, history, services, qr, refund, accepted, accuracy, autorefund, discount · **с v1.1.0:** createBatch, refundBatch, sendEmail, resolve
- `payouts()` — create, createMass, info, history, services, calculate, approve, fee-config, refund · **с v1.1.0:** createBatch
- `batches()` **(v1.1.0)** — info (прогресс и результаты пачки)
- `links()` **(v1.1.0)** — платёжные ссылки: create, list, info, toggle, publicGet, checkout (алиас `paymentLinks()`)
- `payoutLinks()` **(v1.1.0)** — «крипто-чеки»: create, createBatch (до 500), list, info, cancel + публичные claimInfo/claim (без подписи)
- `splits()` **(v1.1.0)** — splitToAddress, splitToMerchant, createRule, listRules, deleteRule, getConfig, setConfig
- `wallets()` — create, block, blockedAddressRefund, qr
- `account()` — balance, referral, transferToPersonal, vrcs
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

- депозит с малым числом подтверждений «дозревает» сам примерно за 10 минут — либо ускорьте,
  повторив `simulateDeposit` с тем же `txid` и бОльшим `confirmations`;
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
`payouts()->create/createMass`, `account()->transferToPersonal`) уходят с HTTP-заголовком
**`Idempotency-Key`** (UUID v4). Ключ генерируется **один раз до цикла повторов** и одинаков во
всех внутренних ретраях, поэтому таймаут/обрыв сети не создаёт дубль. Заголовок не входит в
подпись и в тело запроса.

- **Ломающее изменение:** SDK больше **не подставляет** `order_id` автоматически — он уходит как
  есть. Если вы полагались на сгенерированный `idem-…`, задавайте `order_id` явно.
- Свой ключ: передайте `'idempotency_key' => '...'` в параметрах создающего вызова (или последним
  аргументом в `createBatch`) — он уйдёт в заголовок.
- Исключение — `payoutLinks()`: эндпоинты `/v1/payout/link*` заголовок не поддерживают, дедуп там —
  через per-link `reference`.
- Для выплат `order_id` по-прежнему обязателен и задаётся вами.

## Свой HTTP-транспорт

По умолчанию — cURL. Подставьте свой (Guzzle/PSR-18/мок), реализовав `Oblodai\Http\Transport`:

```php
$client = new Client($id, $secret, ['transport' => new MyTransport()]);
```

## Лицензия

MIT.
