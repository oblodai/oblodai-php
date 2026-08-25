<?php

declare(strict_types=1);

namespace Oblodai\Webhook;

use Oblodai\Contract\Model\WebhookEvent;
use Oblodai\Contract\Model\WebhookEventFactory;
use Oblodai\Core\Signer;
use Oblodai\Core\Util;
use Oblodai\Exception\SignatureException;

/**
 * Webhook verification — usable on its own, no client and no API key required. Deliveries are
 * signed as:
 *
 *   X-Webhook-Timestamp: <unix seconds>
 *   X-Webhook-Signature: hex(HMAC-SHA256(secret, "<ts>." + rawBody))
 *   X-Webhook-Signature-Prev: same, with the previous secret — only during a rotation overlap
 *   X-Webhook-Event: invoice.<status> | payout.<status> | wallet.paid
 *   X-Webhook-Id: stable per delivery (identical across retries) — use it as your idempotency key
 *   X-Webhook-Event-Time: unix seconds when the state change committed (order events by it)
 *
 * Always verify over the RAW request bytes (`file_get_contents('php://input')`); a re-serialized
 * parse will not match.
 *
 * ```php
 * $delivery = Verifier::verify(file_get_contents('php://input'), getallheaders(), $secret);
 * ```
 */
final class Verifier
{
    public const HEADER_TIMESTAMP = 'X-Webhook-Timestamp';
    public const HEADER_SIGNATURE = 'X-Webhook-Signature';
    public const HEADER_SIGNATURE_PREV = 'X-Webhook-Signature-Prev';
    public const HEADER_EVENT = 'X-Webhook-Event';
    public const HEADER_ID = 'X-Webhook-Id';
    public const HEADER_EVENT_TIME = 'X-Webhook-Event-Time';

    /** Reject deliveries whose timestamp is further away than this, seconds. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verify the signature and freshness, then parse. Throws `SignatureException`; never returns an
     * unverified body.
     *
     * @param array<string, mixed> $headers any header shape a framework hands you: `getallheaders()`,
     *                                      a PSR-7 `getHeaders()`, or `$_SERVER`
     * @param string|null $previousSecret during a rotation, the outgoing secret (keep it ≥26 h)
     * @param int         $toleranceSec   0 disables the freshness window
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
        $tsRaw = Util::header($headers, self::HEADER_TIMESTAMP);
        $signature = Util::header($headers, self::HEADER_SIGNATURE);
        if ($tsRaw === null || $tsRaw === '' || $signature === null || $signature === '') {
            throw new SignatureException(
                SignatureException::MISSING_HEADER,
                sprintf('missing %s or %s', self::HEADER_TIMESTAMP, self::HEADER_SIGNATURE)
            );
        }
        if (preg_match('/^-?\d+$/', $tsRaw) !== 1) {
            throw new SignatureException(
                SignatureException::BAD_SIGNATURE,
                'timestamp header is not an integer'
            );
        }
        $ts = (int) $tsRaw;

        if ($toleranceSec > 0) {
            $current = $now ?? time();
            if (abs($current - $ts) > $toleranceSec) {
                throw new SignatureException(
                    SignatureException::STALE_TIMESTAMP,
                    sprintf('delivery timestamp %d is outside the ±%ds window', $ts, $toleranceSec)
                );
            }
        }

        $previousSignature = Util::header($headers, self::HEADER_SIGNATURE_PREV);
        // A merchant who has not swapped the stored secret yet verifies the Prev header with it; one
        // who already swapped but kept the old copy verifies the main header with the new secret.
        $candidates = [[$signature, $secret]];
        if ($previousSignature !== null && $previousSignature !== '') {
            $candidates[] = [$previousSignature, $secret];
        }
        if ($previousSecret !== null && $previousSecret !== '') {
            $candidates[] = [$signature, $previousSecret];
            if ($previousSignature !== null && $previousSignature !== '') {
                $candidates[] = [$previousSignature, $previousSecret];
            }
        }
        $ok = false;
        foreach ($candidates as [$provided, $candidateSecret]) {
            if (Util::constantTimeEquals(strtolower($provided), Signer::signWebhook($candidateSecret, $ts, $rawBody))) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new SignatureException(
                SignatureException::BAD_SIGNATURE,
                'signature does not match the body'
            );
        }

        $eventTime = Util::header($headers, self::HEADER_EVENT_TIME);

        return new Delivery(
            event: self::parse($rawBody),
            id: Util::header($headers, self::HEADER_ID),
            eventType: Util::header($headers, self::HEADER_EVENT),
            eventTime: $eventTime !== null && preg_match('/^\d+$/', $eventTime) === 1 ? (int) $eventTime : null,
            sentAt: $ts,
        );
    }

    /** Parse a (previously verified) delivery body into a typed event, discriminated by `type`. */
    public static function parse(string $rawBody): WebhookEvent
    {
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            throw new SignatureException(SignatureException::BAD_SIGNATURE, 'body is not JSON');
        }

        /** @var array<string, mixed> $body */
        return WebhookEventFactory::fromArray($body);
    }

    /**
     * Deliveries can arrive out of order (a retried `paid` after a `refund`). Keep the last
     * `sequence` you processed per object and skip anything not newer.
     */
    public static function isStale(WebhookEvent $event, ?int $lastProcessedSequence): bool
    {
        return $lastProcessedSequence !== null && $event->sequence() <= $lastProcessedSequence;
    }
}
