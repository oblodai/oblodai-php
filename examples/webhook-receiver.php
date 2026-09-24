<?php

declare(strict_types=1);

/**
 * A webhook endpoint. Point `url_callback` (or the endpoint you registered with
 * `webhooks->register()`) at this script; try it locally with
 * `OBLODAI_WEBHOOK_SECRET=… php -S 127.0.0.1:8096 examples/webhook-receiver.php`.
 *
 * Three rules:
 *  1. verify over the RAW request bytes — a re-encoded parse will not match the signature;
 *  2. deduplicate on `$delivery->eventId` (`X-Webhook-Event-Id`), stable per state;
 *  3. drop out-of-order deliveries with `Verifier::isStale($event, $lastSequence)`.
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

$eventId = $delivery->eventId ?? $delivery->id;
if (DeliveryLog::seen($eventId)) {
    answer(200, 'duplicate');
}
if ($delivery->isTest) {
    answer(200, 'rehearsal - not applied');                // signed like a live one; no money moved
}
$object = is_string($delivery->event['uuid'] ?? null) ? $delivery->event['uuid'] : '';
if (Verifier::isStale($delivery->event, DeliveryLog::lastSequence($object))) {
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
    default => 'unmodelled type ' . (is_string($delivery->event['type'] ?? null) ? $delivery->event['type'] : '?'),
};
$sequence = $delivery->event['sequence'] ?? null;
DeliveryLog::remember($eventId, $object, is_int($sequence) ? $sequence : null);
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
 * Stands in for your database. In production this is a row per event id (unique index) and the
 * last applied `sequence` per object — both must survive a restart, which a process-local array
 * obviously does not.
 */
final class DeliveryLog
{
    /** @var array<string, true> */
    private static array $events = [];

    /** @var array<string, int> */
    private static array $sequences = [];

    public static function seen(?string $eventId): bool
    {
        return $eventId !== null && isset(self::$events[$eventId]);
    }

    public static function lastSequence(string $objectUuid): ?int
    {
        return self::$sequences[$objectUuid] ?? null;
    }

    public static function remember(?string $eventId, string $objectUuid, ?int $sequence): void
    {
        if ($eventId !== null) {
            self::$events[$eventId] = true;
        }
        if ($sequence !== null && $objectUuid !== '') {
            self::$sequences[$objectUuid] = $sequence;
        }
    }
}
