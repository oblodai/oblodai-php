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

- `payments()` — create, info, history, services, qr, refund, accepted, accuracy, autorefund, discount
- `payouts()` — create, createMass, info, history, services, calculate, approve, fee-config, refund
- `wallets()` — create, block, blockedAddressRefund, qr
- `account()` — balance, referral, transferToPersonal, vrcs
- `webhooks()` — register, deliveries, testPayment/Wallet/Payout
- `settings()` — auto-withdraw, IP-allowlist
- `rates()` — list (курсы), currencies (каталог, публичный)

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

Повторы безопасны за счёт идемпотентности по `order_id`: бэкенд дедуплицирует платежи и переводы
по этому полю. Если вы не передали `order_id` в `payments()->create()` или
`account()->transferToPersonal()`, SDK сам подставит стабильный ключ (`idem-…`), одинаковый во всех
попытках, — так повтор после таймаута не создаёт дубль. Для выплат (`payouts()`) `order_id`
обязателен и задаётся вами.

## Свой HTTP-транспорт

По умолчанию — cURL. Подставьте свой (Guzzle/PSR-18/мок), реализовав `Oblodai\Http\Transport`:

```php
$client = new Client($id, $secret, ['transport' => new MyTransport()]);
```

## Лицензия

MIT.
