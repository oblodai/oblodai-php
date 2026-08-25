<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\DeliveryStatus;

/** Item of `POST /v1/webhooks/deliveries` and `GET /v1/sandbox/webhooks` (which adds `payload`, drops `sequence`). */
final class WebhookDelivery
{
    /** @var list<string> */
    public const KEYS = [
        'id', 'url', 'event_type', 'status', 'attempts', 'last_error', 'sequence', 'created_at',
        'updated_at',
    ];

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>      $raw
     */
    public function __construct(
        /** Delivery id. */
        public readonly string $id,
        /** Callback URL this attempt was sent to. */
        public readonly string $url,
        /** Wire event type (for example `invoice.paid`). */
        public readonly string $event_type,
        /** `pending` | `delivered` | `dead`. */
        public readonly DeliveryStatus $status,
        /** Number of delivery attempts so far. */
        public readonly int $attempts,
        /** Last transport error, if any. */
        public readonly string $last_error,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Time of the last change (RFC 3339). */
        public readonly string $updated_at,
        /** Global event sequence, when reported (absent on the `GET /v1/sandbox/webhooks` listing). */
        public readonly ?int $sequence = null,
        /** The delivered event body, when the sandbox listing includes it. */
        public readonly ?array $payload = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $payload = array_key_exists('payload', $data) && is_array($data['payload'])
            ? Wire::obj($data, 'payload')
            : null;

        return new self(
            Wire::str($data, 'id'),
            Wire::str($data, 'url'),
            Wire::str($data, 'event_type'),
            Wire::enum(DeliveryStatus::class, $data, 'status'),
            Wire::int($data, 'attempts'),
            Wire::str($data, 'last_error'),
            Wire::str($data, 'created_at'),
            Wire::str($data, 'updated_at'),
            Wire::nullableInt($data, 'sequence'),
            $payload,
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
