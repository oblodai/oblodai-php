<?php

declare(strict_types=1);

/**
 * A webhook endpoint. Point `url_callback` (or the endpoint you registered with
 * `webhooks->register()`) at this script.
 *
 * Three rules:
 *  1. verify over the RAW request bytes — a re-encoded parse will not match the signature;
 *  2. deduplicate on `X-Webhook-Id`, which is stable across delivery retries;
 *  3. drop out-of-order deliveries with `Verifier::isStale($event, $lastSequence)`.
 *
 * Answer 2xx quickly; the gateway retries anything else for about 26 hours. Which is exactly why
 * the three failure shapes below get three different answers.
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Contract\Model\PaymentEvent;
use Oblodai\Contract\Model\PayoutEvent;
use Oblodai\Contract\Model\WalletEvent;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Webhook\Verifier;

$rawBody = (string) file_get_contents('php://input');
$headers = incoming_headers();

// The signing secret belongs to the ENDPOINT (webhooks->register()), not to an API key. `getenv()`
// answers false when the variable is unset, and the cast would turn that into '' — a secret the SDK
// refuses, but only because it refuses: never verify with an empty key.
$secret = getenv('OBLODAI_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    // Our misconfiguration, not a bad delivery. A 5xx makes the gateway retry until we fix it.
    http_response_code(500);
    error_log('OBLODAI_WEBHOOK_SECRET is not set — cannot verify webhooks');

    exit;
}

try {
    $delivery = Verifier::verify(
        rawBody: $rawBody,
        headers: $headers,
        secret: $secret,
        // During a rotation keep the outgoing secret here for at least 26 hours: deliveries queued
        // before the rotation stay signed with it for their whole retry life.
        previousSecret: getenv('OBLODAI_WEBHOOK_SECRET_PREV') ?: null,
    );
} catch (SignatureException $err) {
    // Bad signature, stale timestamp or missing headers: this did not come from Oblodai.
    http_response_code(401);
    error_log('rejected webhook: ' . $err->getMessage());

    exit;
} catch (WebhookPayloadException $err) {
    // The signature verified, so the delivery IS ours — the body is just unreadable. Answering 401
    // here would make the gateway redeliver an authentic event for a day; acknowledge and alert.
    http_response_code(200);
    error_log('authentic but unreadable webhook: ' . $err->getMessage());

    exit;
} catch (ConfigException $err) {
    // Something about OUR setup is wrong (empty secret, negative tolerance).
    http_response_code(500);
    error_log('webhook receiver misconfigured: ' . $err->getMessage());

    exit;
}

$event = $delivery->event;

// 1. Deduplicate: the same delivery id may arrive several times.
if (alreadyProcessed($delivery->id)) {
    http_response_code(200);

    exit;
}

// 2. Rehearsal deliveries (webhooks->test(), sandbox) are signed exactly like live ones and carry
//    `test: true` (plus X-Webhook-Test: true). Acknowledge them, but never move money for one.
if ($delivery->isTest) {
    error_log('rehearsal webhook ' . $event->type() . ' ' . $event->uuid() . ' — not applied');
    http_response_code(200);

    exit;
}

// 3. Drop anything not newer than what you already applied to this object.
if (Verifier::isStale($event, lastSequenceFor($event->uuid()))) {
    http_response_code(200);

    exit;
}

// 4. Apply it. The event is one of three modelled shapes, discriminated by `type`; anything newer
//    than this SDK arrives as an UnknownEvent and must not be treated as a failure.
if (!Verifier::isKnownEvent($event)) {
    error_log('webhook of an unmodelled type: ' . $event->type() . ' — acknowledged, not applied');
} elseif ($event instanceof PaymentEvent) {
    // `status` carries the raw wire string whether or not this SDK knows the value, so a status
    // added after this release lands in neither branch instead of throwing.
    if ($event->status->isOneOf('paid', 'paid_over')) {
        markOrderPaid((string) $event->order_id, $event->payment_amount, $event->payer_currency);
    } elseif ($event->status->is('wrong_amount')) {
        // Underpaid: decide with refunds->resolve(['uuid' => …, 'action' => 'accept'|'refund']).
        flagUnderpayment($event->uuid());
    }
} elseif ($event instanceof PayoutEvent) {
    recordPayoutState($event->uuid(), $event->status->value, $event->txid);
} elseif ($event instanceof WalletEvent) {
    creditCustomer($event->address, $event->payment_amount, $event->payer_currency);
}

remember($delivery->id, $event->uuid(), $event->sequence());
http_response_code(200);

/**
 * Request headers in whatever shape this SAPI offers: `getallheaders()` under Apache/FPM, the
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

// --- your storage; these stubs stand in for it -------------------------------------------------

/**
 * Stands in for your database. In production this is a row per delivery id (unique index) and the
 * last applied `sequence` per object — both must survive a restart, which a process-local array
 * obviously does not.
 */
final class DeliveryLog
{
    /** @var array<string, true> */
    private static array $deliveries = [];

    /** @var array<string, int> */
    private static array $sequences = [];

    public static function seen(?string $deliveryId): bool
    {
        return $deliveryId !== null && isset(self::$deliveries[$deliveryId]);
    }

    public static function lastSequence(string $objectUuid): ?int
    {
        return self::$sequences[$objectUuid] ?? null;
    }

    public static function remember(?string $deliveryId, string $objectUuid, ?int $sequence): void
    {
        if ($deliveryId !== null) {
            self::$deliveries[$deliveryId] = true;
        }
        if ($sequence !== null) {
            self::$sequences[$objectUuid] = $sequence;
        }
    }
}

function alreadyProcessed(?string $deliveryId): bool
{
    return DeliveryLog::seen($deliveryId);
}

function lastSequenceFor(string $objectUuid): ?int
{
    return DeliveryLog::lastSequence($objectUuid);
}

function remember(?string $deliveryId, string $objectUuid, ?int $sequence): void
{
    DeliveryLog::remember($deliveryId, $objectUuid, $sequence);
}

function markOrderPaid(string $orderId, string $amount, string $currency): void
{
}

function flagUnderpayment(string $invoiceUuid): void
{
}

function recordPayoutState(string $payoutUuid, string $status, string $txid): void
{
}

function creditCustomer(string $address, string $amount, string $currency): void
{
}
