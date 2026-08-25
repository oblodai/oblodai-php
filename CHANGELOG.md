# Changelog

## 1.3.0 — 2026-08-25

Rewrite generated from the gateway's contract snapshot (`contract/`). See MIGRATION-1.3.md.

- Fixed: requests are signed with the five-field recipe (`ts\nMETHOD\nrequestURI\nidempotencyKey\nbody`)
  over path + raw query, with an EMPTY idempotency slot when no key is sent. 1.2 signed four fields
  and got 401 on every call against the current gateway.
- Fixed: models, statuses, pagination and parameter names match the current API vocabulary; amounts
  are decimal strings everywhere.
- Added: every merchant route (107) — cancel/validate, batches, documents, fee configs, split opt-in,
  secret rotation, the payer-facing checkout endpoints and the recipient-facing claim endpoints.
- Added: `Oblodai\Core\Page` (first page via `items()`, every page by iterating; nothing requested
  until consumed), retries driven by the API's own `retryable` flag, automatic idempotency keys,
  clock-skew correction, dual key pairs, per-attempt timeout and per-call deadline.
- Added: `Oblodai\Webhook\Verifier` — rotation-aware verification over the raw bytes, with
  `parse()` and `isStale()`; no client and no API key needed.
- Added: generated request DTOs (`Oblodai\Contract\Request\*`) carrying the gateway's English field
  documentation, generated enums (`Oblodai\Contract\Enum\*`) and the route registry
  (`Oblodai\Contract\Routes`); `composer check-drift` fails when they drift from `contract/`.
- Added: contract tests against the golden response bodies and real signed webhook deliveries, and a
  live journey against a running gateway (`composer test-live`).
- Changed: PHP ≥ 8.1; readonly value objects for every response body, each keeping the raw wire body
  in `->raw` and its wire keys in `::KEYS`; errors are an `OblodaiException` hierarchy carrying
  `errorCode`, `httpStatus`, `retryable`, `retryAfter`, `requestId`, `field` and `synthetic`.
- Changed: HTTP is a small `HttpClient` port — cURL by default, any PSR-18 client through
  `Oblodai\Http\Psr18HttpClient`.
- Changed: closed vocabularies decode into PHP enums; a value outside the shipped snapshot raises
  `ContractException` rather than being silently accepted, while open vocabularies (`network`,
  `kind`, `fee_type`, `source`) stay plain strings.

Значимые изменения этого пакета. Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
версии — [SemVer](https://semver.org/lang/ru/).

## [1.2.0] — 2026-07-19

### ЛОМАЮЩЕЕ
- **Не-https `base_url` больше не принимается.** Раньше клиент молча соглашался на `http://` и
  отправлял подпись запроса (`X-Signature`) в открытый канал, где её видит и может переиграть
  любой посредник на пути. Теперь схема проверяется в конструкторе: не `https` — `ConfigException`
  с внятным текстом. Обязательное исключение — **loopback** (`localhost`, `127.0.0.0/8`, `::1`,
  домены `*.localhost`): по нему работают локальные стенды, включая `http://localhost:8095`,
  и они продолжают работать как раньше. Затрагивает и `OBLODAI_BASE_URL` в `fromEnv()`.
  Если внешний адрес был указан по http — исправьте схему; проксировать https-терминацию через
  открытый http-хоп нельзя.
- `webhooks()->deliveries()` возвращает **список** доставок, а не конверт
  `['deliveries' => [...]]`. Это был единственный метод SDK, где конверт приходилось разворачивать
  вручную: соседний `sandbox()->listWebhooks()` уже отдавал список, и в остальных SDK
  (Node/Python/Go) обе доставочные ручки тоже отдают массив. Миграция:
  `$res['deliveries']` → сам `$res`.

### Добавлено
- **Developer sandbox** `sandbox()` — пять тестовых хелперов, заменяющих «покупатель заплатил
  on-chain» (существуют только в песочнице; live-ключ получает 403 `sandbox.live_key`):
  - `simulateDeposit($invoiceId, $opts)` — симуляция депозита в счёт (`amount` для недо-/переплаты,
    `confirmations` для «зависшего» депозита, повтор с тем же `txid` углубляет подтверждения);
  - `faucet($asset, $amount, $idempotencyKey)` — тестовый баланс (не более 1000000 за вызов);
  - `reset()` — отмена открытых счетов и обнуление балансов;
  - `listWebhooks()` — журнал доставок вебхуков (до 50, новые первыми);
  - `replayWebhook($deliveryId)` — повторная постановка доставки в очередь.
- Подписанные GET-запросы: `Client::requestGet($path)` — та же каноническая строка HMAC, что и у
  POST, с **пустым** телом (`"{ts}\nGET\n{path}\n"`). Используется `sandbox()->listWebhooks()`.
- Хелпер `Client::isTestKey($key)` — тестовый ли ключ (`test_…` / `oblodai_test_…`); удобен как
  гард «sandbox-методы только на тесте». Бизнес-эндпоинты с тестовыми ключами работают без
  изменений — интеграционный код между test и live не меняется, меняется только ключ.
- **Переводы пользователям платформы** (ключ выплат):
  - `account()->transferToUser(['to_user_id' => …, 'amount' => …, 'currency' => …, 'order_id' => …])` —
    `POST /v1/transfer/to-user`, внутренний перевод **без комиссии** с баланса мерчанта на личный
    кошелёк пользователя Oblodai; `to_user_id` — UUID пользователя платформы (НЕ юзернейм),
    `order_id` необязателен. Ответ: `{currency, amount, to_user_id, recipient_balance}`;
  - `account()->transferBatch($transfers, $onError, $idempotencyKey)` — `POST /v1/transfer/batch`,
    пачка переводов в фоне (элементы — тела `transferToUser()`, `on_error`: `continue`|`stop`);
    возвращает `batch_id`, прогресс и результаты — через `batches()->info()`.
  - Оба уходят с заголовком `Idempotency-Key` (та же лестница дедупа, что у остальных денежных
    вызовов: заголовок → `order_id` → подпись); свой ключ — параметром `idempotency_key`.
- **Публичные эндпоинты счёта** для собственных checkout-страниц (без подписи, без API-ключа
  на фронте — тот же механизм, что `links()->publicGet` / `payoutLinks()->claimInfo`):
  - `payments()->publicGet($uuid)` — `GET /v1/pay/{id}`, публичное состояние счёта;
  - `payments()->publicSelect($uuid, $currency, $network)` — `POST /v1/pay/{id}/select`,
    финализация отложенного (валюто-агностичного) счёта: покупатель выбирает валюту/сеть,
    ответ — обычный результат платежа (`address`, `payment_status`, …).

### Исправлено
- **Контракт типов: ответ `{"state":0,"result":null}` больше не роняет TypeError.**
  `Client::request/requestIdempotent/requestGet/requestPublic` были объявлены `@return mixed`,
  а **каждый** метод ресурса — `: array`. Любой ответ с пустым `result` (конверт шлюза его не
  запрещает: `apiutil.WriteResult` в ядре сериализует любое nil-значение в `null`; телом может
  прийти и голый `null`) давал `TypeError` — а он наследует `Error`, а не `Exception`, и потому
  **пролетал мимо `catch (OblodaiException)` из собственного README**. Теперь объявления приведены
  к реальности: все четыре метода клиента возвращают `array`, `null` нормализуется в `[]`
  (пустое тело — тоже `[]`). Скаляр в `result` шлюз сегодня не отдаёт ни на одном эндпоинте, но
  если бы отдал — это `ApiException` с кодом `response.unexpected_shape`, то есть штатная ошибка
  SDK, а не разрыв иерархии исключений.
- **`retry.max_attempts = 0` больше не даёт фатальную ошибку.** При нуле (и при отрицательном
  значении) цикл повторов не выполнялся ни разу, и метод доходил до хвостового `throw $lastError`
  с `$lastError === null` — то есть `throw null`: фатальная ошибка вместо исключения, не ловится
  ничем. Теперь значение клампится к `>= 1` (одна попытка, без повторов; отключить повторы
  по-прежнему `['retry' => false]`), `initial_delay_ms`/`max_delay_ms` клампятся к `>= 0`, а сам
  хвостовой `throw` заменён на настоящее исключение SDK.
- **`Retry-After` в форме HTTP-date больше не выбрасывается.** RFC 7231 разрешает две формы
  заголовка, а `CurlTransport` учитывал только `is_numeric` — то есть `Retry-After:
  Wed, 21 Oct 2026 07:28:00 GMT` (так может отвечать прокси или балансировщик перед шлюзом) молча
  терялся, клиент откатывался на собственный backoff и бил в 429 **раньше**, чем его просили.
  Теперь дата разбирается в остаток от «сейчас». Результат зажат в `[0; 300]` секунд — тот же
  потолок в 5 минут, что в Node/Python/Go SDK; дата в прошлом означает «можно сразу»,
  нераспознанное значение — «бери свой backoff». Потолок теперь клампится **дважды** — при разборе
  заголовка в транспорте и при расчёте паузы в клиенте (`Client::retryAfterDelayMs()`, вынесен из
  приватного `delayMicros()`, чтобы потолок проверялся тестом без реального ожидания), — так что
  подсказка от стороннего транспорта тоже не подвесит процесс на сутки.
- **Добавлен таймаут установления соединения** (`CURLOPT_CONNECTTIMEOUT_MS`) — треть общего
  таймаута. Раньше мёртвый или чёрнодырящий хост съедал весь бюджет запроса на одном TCP-хендшейке,
  и на повторы времени не оставалось. `0` («без ограничения») сохраняется как есть.
- **Денежный шов: авто-ретрай мог зарезервировать баланс дважды.** `payoutLinks()->create()` и
  `payoutLinks()->createBatch()` резервируют средства, но шли **без** ключа идемпотентности —
  потерянный ответ (таймаут/5xx) означал до трёх автоматических повторов и, соответственно, до
  четырёх профинансированных ссылок с одного баланса. Теперь оба уходят с заголовком
  `Idempotency-Key`, стабильным между попытками, — и бэкенд (core `8dffa7b`) обёрнул оба маршрута
  в серверную идемпотентность, так что повтор с тем же ключом реплеит **первый ответ целиком**:
  ту же ссылку, тот же `claim_token`, заголовок ответа `Idempotent-Replayed: true`, баланс
  дебетуется ровно один раз. **Без** заголовка поведение прежнее: два одинаковых вызова создадут
  две ссылки. Свой ключ: параметр `idempotency_key` у `create()`, второй аргумент
  `$idempotencyKey` у `createBatch()`. Per-link `reference` остаётся вторым, durable рубежом —
  он работает и без заголовка, и там, где реплей недоступен.
  Оговорка: частично упавшая пачка реплеится **как есть** — упавшие элементы под тем же ключом не
  переобрабатываются, допинывайте их новым вызовом с новым ключом. И отдельно: ответ батча больше
  **256 КБ не кэшируется**, тогда повтор выполнится заново — на батчах обязательно проставляйте
  per-item `reference`.
- Дубль `reference` на payout-ссылке теперь `409 payoutlink.duplicate_reference` вместо прежнего
  `500`. Для SDK это важно: `500` авто-ретраился (и упирался в ту же ошибку), `409` терминален.
- `wallets()->blockedAddressRefund()` тоже переведён на идемпотентный путь (+ опциональный третий
  аргумент `$idempotencyKey`). Маршрут при этом намеренно НЕ обёрнут серверной идемпотентностью:
  он и так once-only по кошельку (стабильный reference `refund-wallet:<walletID>`, advisory lock,
  поиск существующей выплаты внутри лока), так что дублей не было и раньше, а обёртка отдавала бы
  конкурентному повтору `409` вместо ожидания и успеха. Оговорка зафиксирована в доке: адрес в
  reference не входит, поэтому повтор с другим адресом вернёт первую выплату на первый адрес.

### Документировано
- Коды ответов идемпотентности на денежных создающих маршрутах: `400 idempotency.bad_key`,
  `400 idempotency.key_reused` (тот же ключ с другим телом), `409 idempotency.in_progress`
  (первый запрос ещё выполняется), `503 idempotency.unavailable` (стор недоступен, fail-closed),
  `409 payoutlink.duplicate_reference`. Сверено с классификацией SDK: `isRetriable()` истинна
  только для 5xx и 429, то есть `400`/`409` терминальны, а `503` повторяется с тем же ключом.
- `payouts()->approve()`: идемпотентность не нужна и не шлётся — это переход состояния,
  принимается только `pending`, иначе `409 payout.not_pending`, который следует читать как
  «уже одобрено» и уточнять через `payouts()->info()`.

### Документация
- **«Где взять ключи» — первым блоком сразу после установки.** Претензия №1 во всех пяти ревью:
  читатель видел имена переменных окружения и не понимал, откуда берутся значения. Теперь сказано
  прямо: ключи выдаются в личном кабинете Oblodai (<https://oblodai.com>) в разделе API-ключей,
  секрет показывается **один раз** при создании, для песочницы выпускается тестовый ключ вида
  `test_…` / `oblodai_test_…`.
- **Песочница поднята сразу за быстрым стартом.** Раньше раздел был закопан за батчами, сплитами
  и выплатными ссылками, и читающий сверху вниз подключал БОЕВЫЕ ключи раньше, чем узнавал о
  безопасной площадке. Плейсхолдер секрета в блоке с переменными окружения сменён с
  `oblodai_live_…` на `oblodai_test_…`, и добавлена явная строка: тот же код работает с боевым
  ключом, меняется только ключ.
- **Единое имя ресурса платёжных ссылок.** Канон во всех пяти SDK — `payment_links`
  (в идиоматике языка: `paymentLinks()` в PHP/JS, `payment_links` в Python/Rust, `PaymentLinks()`
  в Go), чтобы код переносился между языками без переименований. В PHP оба имени существовали и
  раньше; теперь каноническим объявлен `paymentLinks()`, а короткий `links()` — **документированный
  алиас**. Ничего не удалено, оба имени равнозначны и поддерживаются; в README упомянуты оба.
- Требование https к `base_url` и loopback-исключение описаны в разделе «Где взять ключи».
- Раздел «Обработка ошибок» перечисляет иерархию исключений SDK (все наследуют
  `OblodaiException`) и фиксирует новый контракт: методы ресурсов возвращают массив при любом
  ответе шлюза, пустой `result` — это `[]`, а не `TypeError` мимо `catch`.
- **Убрано ложное утверждение о «дозревании» sandbox-депозита.** README и doc-комментарий
  `simulateDeposit()` обещали, что депозит с малым `confirmations` подтвердится сам примерно за
  10 минут. Это неправда: симулированный депозит никто не переэмитит и курсор для него не
  двигается — счёт висит в `confirm_check` неограниченно долго. Единственный способ довести его
  до `paid` — повторить `simulateDeposit` с **тем же** `txid` и бОльшим `confirmations`.
  Были слиты два разных механизма: ~10 минут относятся к maturity-**холду на выплате**
  (ошибка `payout.funds_maturing`), который в песочнице снимается по возрасту фоновым джобом
  (`GATEWAY_SANDBOX_MATURITY_MINUTES`, по умолчанию 10) и касается только баланса. Теперь
  в текстах это разведено.
- Раздел «Идемпотентность» в README больше не утверждает, что `/v1/payout/link*` не поддерживают
  `Idempotency-Key`.
- **Регистрация вебхука — это upsert единственного эндпоинта проекта.** README и докблок
  `webhooks()->register()` предупреждают: в ядре
  `INSERT ... ON CONFLICT (project_id) DO UPDATE SET url = EXCLUDED.url`, поэтому повторный вызов
  с другим URL не добавляет второй endpoint, а **перенаправляет** доставки — тот же `endpoint_id`,
  старый URL молча замолкает (классическая авария: регистрация staging-URL из локального скрипта
  убивает прод-вебхуки). Секрет при перерегистрации сохраняется — иначе уже поставленные в
  очередь доставки, подписанные им, протухли бы. Там же подчёркнуто, что секрет подписи — это
  **отдельный секрет эндпоинта**, а не секрет API-ключа: подставив ключ API, интегратор отвергнет
  100% вебхуков.
- **Таблица статусов платежа** в README (`check`, `confirm_check`, `wrong_amount_waiting`,
  `wrong_amount`, `paid`, `paid_over`, `cancel`, `select`) с указанием терминальности и отсылкой
  к флагу `is_final`; статусы выплаты — `check` → `process` → `paid`, плюс `fail`/`cancel`.
  Отдельно разведены `wrong_amount_waiting` (частичная оплата, счёт ещё жив — можно доплатить) и
  `wrong_amount` (счёт закрылся недоплаченным), и объяснено следствие: `payments()->resolve()`
  принимает только `wrong_amount`, а на `wrong_amount_waiting` отвечает
  `409 resolution.not_underpaid` — терминальная ошибка, а не повод для ретрая.
- **Оговорка про пустые ссылки.** `payment['url']`, `link['url']` и `check['claim_url']` шлюз
  склеивает из своего публичного базового URL (`GATEWAY_PUBLIC_BASE_URL`); без него они приходят
  **пустой строкой**. В проде это невозможно (шлюз без настройки не стартует), но на локальном
  стенде встречается постоянно — и это не баг SDK. В README показано, как собрать ссылку самому
  из `uuid` / `claim_token`.
- **Уточнён `sandbox()->reset()`: это не «чистый лист».** Отменяются только счета в статусах
  `created` (API `check`) и `select`; счёт, по которому депозит уже виден (`confirm_check`,
  `wrong_amount_waiting`), сознательно не трогается — отмена дала бы депозиту подтвердиться в
  отменённый счёт. Ничего не удаляется: леджер append-only, обнуление баланса — компенсирующая
  проводка.

## [1.1.0] — 2026-07-15

### ЛОМАЮЩЕЕ
- Идемпотентность переведена с авто-подстановки `order_id` на HTTP-заголовок **`Idempotency-Key`**:
  - `payments()->create()` и `account()->transferToPersonal()` больше **не генерируют** `order_id`
    (`idem-…`), если он не задан, — `order_id` уходит в тело **как есть**. Если ваш код полагался
    на сгенерированное значение в ответе, задавайте `order_id` явно.
  - Взамен все создающие вызовы (`payments()->create/refund/resolve`, `payouts()->create/createMass`,
    `createBatch`/`refundBatch`, `transferToPersonal`) шлют заголовок `Idempotency-Key` (UUID v4),
    сгенерированный **один раз до цикла повторов** — все внутренние ретраи несут одно значение,
    и повтор после таймаута не создаёт дубль. Заголовок не входит в подпись и в тело.
  - Свой ключ — новым опциональным параметром `idempotency_key` (в массиве параметров либо
    аргументом `$idempotencyKey` у батч-методов): уйдёт в заголовок, не в тело.
  - Исключение: `payoutLinks()` (`/v1/payout/link*`) заголовок не поддерживает — дедупликация
    там через per-link `reference`.

### Добавлено
- **Массовые операции** (до 5000 элементов одним запросом): `payments()->createBatch()`,
  `payments()->refundBatch()`, `payouts()->createBatch()` и `batches()->info()` (прогресс и
  результаты по элементам). Режим `on_error`: `continue` (по умолчанию) или `stop`.
- **Платёжные ссылки** `links()` (алиас `paymentLinks()`): `create`, `list`, `info`, `toggle` +
  публичные `publicGet` и `checkout` (без подписи).
- **Payout-ссылки («крипто-чеки»)** `payoutLinks()`: `create`, `createBatch` (до 500), `list`,
  `info`, `cancel` + публичные `claimInfo($token)` (GET `/v1/claim/{token}`, без подписи) и
  `claim($token, $address, $memo)` (POST, без подписи). Задавайте `expires_in_hours` явно —
  без него бэкенд клампит срок жизни ссылки к 1 часу.
- **Сплит-платежи** `splits()`: `splitToAddress`, `splitToMerchant`, `createRule`, `listRules`,
  `deleteRule`, `getConfig`, `setConfig` (окно удержания `refund_hold_hours`).
- **Счёт на e-mail**: `payments()->sendEmail($uuid, $orderId, $email)` — письмо покупателю с
  кнопкой «Оплатить» (лимит бэкенда: 10 писем/час на адрес).
- **Судьба недоплаты**: `payments()->resolve(['uuid'|'order_id' => …, 'action' => 'accept'|'refund'])` —
  оставить частичную оплату себе или вернуть плательщику (глушит авто-возврат).

## [1.0.2] — 2026-07-12

### Исправлено
- Устойчивость к некорректному `Retry-After`: отрицательное значение (например, `Retry-After: -5`)
  больше не приводит к отрицательной задержке и `ValueError` из `usleep()` — задержка клампится
  снизу нулём (`max(0, min(…, 300000))`), повтор корректно продолжается.
- Нормализована проверка «пустого» `order_id` при авто-идемпотентности: ключ подставляется, если
  переданное значение не является непустой строкой после `trim()` — покрывает `null`, `''`,
  пробельные строки (`'   '`) и не-строковые значения. Реальный `order_id` вызывающего сохраняется.

## [1.0.1] — 2026-07-12

### Исправлено
- Безопасность повторов: `payments()->create()` и `account()->transferToPersonal()` теперь
  автоматически подставляют стабильный `order_id` (`idem-…`), если он не задан. Один и тот же ключ
  уходит во всех попытках, поэтому повтор неидемпотентного POST после сетевого/5xx-сбоя не создаёт
  дубль платежа/перевода. Выплаты (`payouts()`) по-прежнему требуют явный `order_id`.
- Заголовок `Retry-After` теперь уважается сверх `max_delay_ms` (кламп только абсолютным потолком
  300 000 мс): `Retry-After: 60` ждёт ~60 с, а не обрезается до `max_delay`.
- Настоящий случайный джиттер в backoff (`random_int`) вместо константы — снижает эффект
  «thundering herd» при одновременных повторах.
- `payout.funds_maturing` больше не считается временной ошибкой (`isRetriable()` → терминальна):
  повтор её не разрешает.
- Docblock `ApiException`: пример `$e->getCode2()` исправлен на `$e->getErrorCode()`.

## [1.0.0] — 2026-07-12

### Добавлено
- Первый релиз официального PHP SDK для платёжного шлюза Oblodai.
- Приём платежей, выплаты и массовые выплаты, статические кошельки, возвраты, вебхуки,
  публичные справочники (курсы валют, каталог монет и сетей).
- Подпись запросов HMAC-SHA256 и проверка подписи вебхуков (constant-time, защита от replay).
- Конструктор из переменных окружения `Client::fromEnv()` — `OBLODAI_PUBLIC_ID` / `OBLODAI_SECRET` /
  `OBLODAI_BASE_URL`.
- Автоматические повторы с экспоненциальным backoff и учётом заголовка `Retry-After` на 429.
- Инъектируемый HTTP-транспорт (по умолчанию cURL, без внешних зависимостей).
