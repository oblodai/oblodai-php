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
 * Answer 2xx quickly; the gateway retries anything else for about 26 hours.
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Contract\Model\PaymentEvent;
use Oblodai\Contract\Model\PayoutEvent;
use Oblodai\Contract\Model\WalletEvent;
use Oblodai\Exception\SignatureException;
use Oblodai\Webhook\Verifier;

$rawBody = (string) file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : $_SERVER;

try {
    $delivery = Verifier::verify(
        rawBody: $rawBody,
        headers: $headers,
        secret: (string) getenv('OBLODAI_WEBHOOK_SECRET'),
        // During a rotation keep the outgoing secret here for at least 26 hours: deliveries queued
        // before the rotation stay signed with it for their whole retry life.
        previousSecret: getenv('OBLODAI_WEBHOOK_SECRET_PREV') ?: null,
    );
} catch (SignatureException $err) {
    // Bad signature, stale timestamp or missing headers: this did not come from Oblodai.
    http_response_code(400);
    error_log('rejected webhook: ' . $err->getMessage());

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

// 4. Apply it. The event is one of three shapes, discriminated by `type`.
if ($event instanceof PaymentEvent) {
    if ($event->status->value === 'paid' || $event->status->value === 'paid_over') {
        markOrderPaid((string) $event->order_id, $event->payment_amount, $event->payer_currency);
    } elseif ($event->status->value === 'wrong_amount') {
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

// --- your storage; these stubs stand in for it -------------------------------------------------

function alreadyProcessed(?string $deliveryId): bool
{
    return false;
}

function lastSequenceFor(string $objectUuid): ?int
{
    return null;
}

function remember(?string $deliveryId, string $objectUuid, int $sequence): void
{
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
