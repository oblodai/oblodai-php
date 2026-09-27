<?php

declare(strict_types=1);

/**
 * A webhook endpoint. Point `url_callback` (or the endpoint you registered with
 * `webhooks->register()`) at this script; try it locally with
 * `OBLODAI_WEBHOOK_SECRET=… php -S 127.0.0.1:8096 examples/webhook-receiver.php`.
 *
 * Four rules:
 *  1. verify over the RAW request bytes — a re-encoded parse will not match the signature;
 *  2. ignore rehearsals (`$delivery->isTest`, the signed body's `test: true`) before anything else;
 *  3. deduplicate on `$delivery->eventKey` (type:objectId:sequence from the SIGNED body), stable
 *     per state — never on the X-Webhook-* id headers, which are not signed;
 *  4. drop out-of-order deliveries with `Verifier::isStale($event, $lastSequence)`, keeping the
 *     last sequence per object: its `type` and `Verifier::objectId($event)` (a payment's `uuid`,
 *     a conversion's `id` — whatever the contract names for the kind).
 *
 * Answer 2xx quickly; the gateway retries anything else for about 26 hours. Which is exactly why
 * the three failure shapes below get three different answers.
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Exception\ConfigException;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Generated\Model\ConversionWebhook;
use Oblodai\Generated\Model\PaymentWebhook;
use Oblodai\Generated\Model\PayoutWebhook;
use Oblodai\Generated\Model\WalletWebhook;
use Oblodai\Helper\Status;
use Oblodai\Webhook\Verifier;

$rawBody = (string) file_get_contents('php://input');

try {
    $delivery = Verifier::verify(
        rawBody: $rawBody,
        headers: incoming_headers(),
        secret: (string) getenv('OBLODAI_WEBHOOK_SECRET'),
        previousSecret: getenv('OBLODAI_WEBHOOK_SECRET_PREV') ?: null,   // during a rotation
    );
} catch (SignatureException $err) {
    answer(401, 'rejected: ' . $err->getMessage());        // not ours, or too old
} catch (WebhookPayloadException $err) {
    answer(200, 'unreadable: ' . $err->getMessage());      // ours, but not the documented body: alert
} catch (ConfigException $err) {
    answer(500, 'misconfigured: ' . $err->getMessage());   // no secret here — fix the receiver
}

if ($delivery->isTest) {
    answer(200, 'rehearsal - not applied');                // signed like a live one; no money moved
}
$eventKey = $delivery->eventKey;                           // from the signed body, not a header
if (DeliveryLog::seen($eventKey)) {
    answer(200, 'duplicate');
}
// A kind this SDK does not know has no known object id: its deliveries are not ordered.
$type = is_string($delivery->event['type'] ?? null) ? $delivery->event['type'] : '';   // parse() checked it
$objectId = Verifier::objectId($delivery->event);
$object = $objectId === null ? null : $type . ':' . $objectId;
if ($object !== null && Verifier::isStale($delivery->event, DeliveryLog::lastSequence($object))) {
    answer(200, 'stale');
}

try {
    $event = Verifier::model($delivery->event);   // the generated model of its `type`, or null
} catch (WebhookPayloadException $err) {
    answer(200, 'unreadable: ' . $err->getMessage());
}

$applied = match (true) {
    $event instanceof PaymentWebhook => Status::isPaymentPaid($event->status)
        ? fulfilOrder($event->order_id, $event->payment_amount, $event->payer_currency)
        : 'payment ' . Status::value($event->status),
    $event instanceof PayoutWebhook => 'payout ' . Status::value($event->status) . ' ' . $event->txid,
    $event instanceof WalletWebhook => 'wallet deposit ' . $event->payment_amount . ' ' . $event->payer_currency,
    $event instanceof ConversionWebhook => 'conversion ' . Status::value($event->status),
    default => 'unmodelled type ' . $type,
};
$sequence = $delivery->event['sequence'] ?? null;
DeliveryLog::remember($eventKey, $object, is_int($sequence) ? $sequence : null);
answer(200, 'ok: ' . $applied);

/**
 * Request headers in whatever shape this SAPI offers: `getallheaders()` under a web server, the
 * `HTTP_*` entries of `$_SERVER` otherwise. `Verifier` reads either.
 *
 * @return array<string, mixed>
 */
function incoming_headers(): array
{
    $raw = function_exists('getallheaders') ? getallheaders() : $_SERVER;
    $out = [];
    foreach ($raw as $name => $value) {
        $out[(string) $name] = $value;
    }

    return $out;
}

function answer(int $status, string $text): never
{
    http_response_code($status);
    echo $text, "\n";

    exit;
}

function fulfilOrder(string $orderId, string $amount, string $currency): string
{
    // Your fulfilment goes here.
    return sprintf('order %s paid with %s %s', $orderId, $amount, $currency);
}

/**
 * Stands in for your database: the handled event keys and the last applied `sequence` per object.
 * PHP forgets everything between requests, so this keeps them in a JSON file
 * (`OBLODAI_WEBHOOK_LOG`, else one in the temp directory). In production it is a row per event key
 * (unique index) and one per object, updated in the transaction that applies the event.
 */
final class DeliveryLog
{
    /** @var array{events: array<string, true>, sequences: array<string, int>}|null */
    private static ?array $state = null;

    public static function seen(?string $eventKey): bool
    {
        return $eventKey !== null && isset(self::state()['events'][$eventKey]);
    }

    public static function lastSequence(string $object): ?int
    {
        return self::state()['sequences'][$object] ?? null;
    }

    public static function remember(?string $eventKey, ?string $object, ?int $sequence): void
    {
        $state = self::state();
        if ($eventKey !== null) {
            $state['events'][$eventKey] = true;
        }
        if ($sequence !== null && $object !== null) {
            $state['sequences'][$object] = $sequence;
        }
        self::$state = $state;
        file_put_contents(self::path(), (string) json_encode($state), LOCK_EX);
    }

    /** @return array{events: array<string, true>, sequences: array<string, int>} */
    private static function state(): array
    {
        if (self::$state === null) {
            $saved = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : null;
            /** @var array{events: array<string, true>, sequences: array<string, int>} $state */
            $state = is_array($saved) ? $saved + ['events' => [], 'sequences' => []] : ['events' => [], 'sequences' => []];
            self::$state = $state;
        }

        return self::$state;
    }

    private static function path(): string
    {
        return getenv('OBLODAI_WEBHOOK_LOG') ?: sys_get_temp_dir() . '/oblodai-webhook-log.json';
    }
}
