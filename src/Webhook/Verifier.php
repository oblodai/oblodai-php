<?php

declare(strict_types=1);

namespace Oblodai\Webhook;

use Oblodai\Core\Signer;
use Oblodai\Core\Util;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Generated\Model\ConversionWebhook;
use Oblodai\Generated\Model\PaymentWebhook;
use Oblodai\Generated\Model\PayoutWebhook;
use Oblodai\Generated\Model\WalletWebhook;

/**
 * Webhook verification — usable on its own, no client and no API key required. Deliveries are
 * signed as:
 *
 *   X-Webhook-Timestamp: <unix seconds>
 *   X-Webhook-Signature: hex(HMAC-SHA256(secret, "<ts>." + rawBody))
 *   X-Webhook-Signature-Prev: same, with the previous secret — only during a rotation overlap
 *   X-Webhook-Event: invoice.<status> | payout.<status> | wallet.paid
 *   X-Webhook-Id: stable per delivery (identical across retries of THAT delivery)
 *   X-Webhook-Event-Id: stable per STATE — the same for a resend of a state you already handled,
 *     different as soon as the state differs; this is the idempotency key to keep
 *   X-Webhook-Event-Time: unix seconds when the state change committed (order events by it)
 *   X-Webhook-Test: "true" on a rehearsal delivery (`webhooks.test`, sandbox) — see `Delivery::$isTest`
 *
 * Always verify over the RAW request bytes (`file_get_contents('php://input')`); a re-serialized
 * parse will not match.
 *
 * ```php
 * $delivery = Verifier::verify(file_get_contents('php://input'), getallheaders(), $secret);
 * $event = Verifier::model($delivery->event);   // PaymentWebhook, PayoutWebhook, … or null
 * ```
 */
final class Verifier
{
    public const HEADER_TIMESTAMP = 'X-Webhook-Timestamp';
    public const HEADER_SIGNATURE = 'X-Webhook-Signature';
    public const HEADER_SIGNATURE_PREV = 'X-Webhook-Signature-Prev';
    public const HEADER_EVENT = 'X-Webhook-Event';
    public const HEADER_ID = 'X-Webhook-Id';
    public const HEADER_EVENT_ID = 'X-Webhook-Event-Id';
    public const HEADER_EVENT_TIME = 'X-Webhook-Event-Time';
    public const HEADER_TEST = 'X-Webhook-Test';

    /**
     * The `type` discriminators this SDK models, with the generated model of each. A delivery naming
     * anything else is still returned — a newer gateway may add a kind, and dropping it would lose
     * a real event.
     */
    public const EVENT_MODELS = [
        'payment' => PaymentWebhook::class,
        'payout' => PayoutWebhook::class,
        'wallet' => WalletWebhook::class,
        'conversion' => ConversionWebhook::class,
    ];

    /** Reject deliveries whose timestamp is further away than this, seconds. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify the signature and freshness, then parse. Never returns an unverified body.
     *
     * The MAC is checked BEFORE the timestamp, on purpose: a freshness window that answers before
     * the signature does is an oracle an unauthenticated caller can probe for the receiver's clock.
     *
     * Failure modes a receiver should distinguish:
     * - `ConfigException` — the receiver is misconfigured (no secret, negative tolerance). Never a
     *   401: nothing about the delivery was wrong.
     * - `SignatureException` — the delivery is not ours (bad/missing signature, stale timestamp).
     *   Answer 401.
     * - `WebhookPayloadException` (a `ContractException`, code `webhook.bad_payload`) — the MAC
     *   verified, so the event IS ours, but
     *   its body is not the documented JSON object. Answer 2xx (or 400) and alert; answering 401
     *   would make the gateway retry an authentic delivery for a day.
     *
     * @param array<string, mixed> $headers any header shape a framework hands you: `getallheaders()`,
     *                                      a PSR-7 `getHeaders()`, or `$_SERVER`
     * @param string      $secret         the endpoint secret; empty is a configuration error, never
     *                                    a key to verify with
     * @param string|null $previousSecret during a rotation, the outgoing secret (keep it ≥26 h)
     * @param int         $toleranceSec   0 disables the freshness window; negative is a config error
     * @param int|null    $now            unix seconds, injectable for tests
     */
    public static function verify(
        string $rawBody,
        array $headers,
        string $secret,
        ?string $previousSecret = null,
        int $toleranceSec = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): Delivery {
        // Before any crypto: an empty key would make HMAC('', body) the expected value, and any
        // forger can compute that.
        if (trim($secret) === '') {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'webhook secret is empty; pass the endpoint secret from webhooks->register()',
                'secret'
            );
        }
        if ($previousSecret !== null && trim($previousSecret) === '') {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'previousSecret was supplied but is empty; pass null when no rotation is in flight',
                'previousSecret'
            );
        }
        if ($toleranceSec < 0) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                sprintf('toleranceSec must be >= 0 (0 disables the freshness window), got %d', $toleranceSec),
                'toleranceSec'
            );
        }

        $tsRaw = Util::header($headers, self::HEADER_TIMESTAMP);
        $signature = self::normalizeSignature(Util::header($headers, self::HEADER_SIGNATURE));
        if ($tsRaw === null || trim($tsRaw) === '' || $signature === null) {
            throw new SignatureException(
                SignatureException::MISSING_HEADER,
                sprintf('missing %s or %s', self::HEADER_TIMESTAMP, self::HEADER_SIGNATURE)
            );
        }
        $tsRaw = trim($tsRaw);
        if (preg_match('/^-?\d+$/', $tsRaw) !== 1) {
            throw new SignatureException(
                SignatureException::BAD_SIGNATURE,
                'timestamp header is not an integer'
            );
        }
        $ts = (int) $tsRaw;

        $previousSignature = self::normalizeSignature(Util::header($headers, self::HEADER_SIGNATURE_PREV));
        // A merchant who has not swapped the stored secret yet verifies the Prev header with it; one
        // who already swapped but kept the old copy verifies the main header with the new secret.
        $candidates = [[$signature, $secret]];
        if ($previousSignature !== null) {
            $candidates[] = [$previousSignature, $secret];
        }
        if ($previousSecret !== null) {
            $candidates[] = [$signature, $previousSecret];
            if ($previousSignature !== null) {
                $candidates[] = [$previousSignature, $previousSecret];
            }
        }
        $ok = false;
        foreach ($candidates as [$provided, $candidateSecret]) {
            if (Util::constantTimeEquals($provided, Signer::signWebhook($candidateSecret, $ts, $rawBody))) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new SignatureException(
                SignatureException::BAD_SIGNATURE,
                'signature does not match the body'
            );
        }

        // Only now, with the delivery proven authentic, does the clock get a say.
        if ($toleranceSec > 0) {
            $current = $now ?? time();
            if (abs($current - $ts) > $toleranceSec) {
                throw new SignatureException(
                    SignatureException::STALE_TIMESTAMP,
                    sprintf('delivery timestamp %d is outside the ±%ds window', $ts, $toleranceSec)
                );
            }
        }

        $eventTime = Util::header($headers, self::HEADER_EVENT_TIME);
        $event = self::parse($rawBody);

        return new Delivery(
            event: $event,
            id: Util::header($headers, self::HEADER_ID),
            eventId: Util::header($headers, self::HEADER_EVENT_ID),
            eventType: Util::header($headers, self::HEADER_EVENT),
            eventTime: $eventTime !== null && preg_match('/^\d+$/', trim($eventTime)) === 1 ? (int) trim($eventTime) : null,
            sentAt: $ts,
            isTest: strtolower(trim((string) Util::header($headers, self::HEADER_TEST))) === 'true' || self::isTestEvent($event),
        );
    }

    /**
     * A signature header as we are willing to read it: surrounding whitespace trimmed, hex in either
     * case, and no `0x` prefix (the core never sends one, and accepting it would let two spellings
     * of the same MAC differ). Null when there is nothing usable.
     */
    private static function normalizeSignature(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = strtolower(trim($value));

        return preg_match('/^[0-9a-f]{2,}$/', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * Parse a (previously verified) delivery body: a JSON object that carries a string `type`.
     *
     * An unreadable body here is `webhook.bad_payload`, NOT a signature failure: the MAC already
     * proved the delivery is ours. An unknown `type` is not a failure at all — see
     * {@see Verifier::isKnownEvent()}.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $rawBody): array
    {
        $body = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new WebhookPayloadException('webhook body is not a JSON object', $rawBody);
        }
        if (!is_string($body['type'] ?? null) || $body['type'] === '') {
            throw new WebhookPayloadException('webhook body has no `type` discriminant', $body);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The event as the generated model of its `type`; null for a type this SDK does not model.
     *
     * @param array<string, mixed> $event
     *
     * @throws WebhookPayloadException when the body does not have the documented fields of its type
     */
    public static function model(array $event): PaymentWebhook|PayoutWebhook|WalletWebhook|ConversionWebhook|null
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $class = self::EVENT_MODELS[$type] ?? null;
        if ($class === null) {
            return null;
        }

        try {
            return $class::fromArray($event);
        } catch (ContractException $err) {
            throw new WebhookPayloadException(sprintf('webhook %s: %s', $type, $err->detail), $event);
        }
    }

    /**
     * True when the event's `type` is one this SDK models (`payment`, `payout`, `wallet`,
     * `conversion`). A false answer is not a failure: the gateway sent a kind of event newer than
     * this release; log it and move on.
     *
     * @param array<string, mixed> $event
     */
    public static function isKnownEvent(array $event): bool
    {
        return is_string($event['type'] ?? null) && isset(self::EVENT_MODELS[$event['type']]);
    }

    /**
     * True for a rehearsal delivery (`webhooks->sendTest*`, sandbox). Such a body is signed like a
     * live one, so a handler must branch on it and never act on it as if money moved.
     *
     * @param array<string, mixed> $event
     */
    public static function isTestEvent(array $event): bool
    {
        return ($event['test'] ?? false) === true;
    }

    /**
     * Deliveries can arrive out of order (a retried `paid` after a `refund`). Keep the last
     * `sequence` you processed per object and skip anything not newer.
     *
     * An event without a usable `sequence` is never stale: dropping it would silently lose a real
     * state change just because the body was newer or older than this SDK expects.
     *
     * @param array<string, mixed> $event
     */
    public static function isStale(array $event, ?int $lastProcessedSequence): bool
    {
        $sequence = $event['sequence'] ?? null;

        return is_int($sequence) && $lastProcessedSequence !== null && $sequence <= $lastProcessedSequence;
    }
}
