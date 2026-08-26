<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/**
 * A delivered webhook whose `type` discriminant is newer than this SDK.
 *
 * The gateway may start sending a kind of event this release never heard of. Throwing on it would
 * make an authentic, correctly signed delivery come back as a 500 and be redelivered for a day, so
 * the SDK hands the body over as it arrived: `type()` is the raw discriminant, `toArray()`/`raw`
 * the whole body, and the usual helpers (`isTest()`, `Verifier::isStale()`) still work.
 */
final class UnknownEvent implements WebhookEvent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The `type` string the core sent — outside `payment|payout|wallet`. */
        public readonly string $type,
        /** The subject id, when the body carries one. */
        public readonly string $uuid,
        /** Global, increasing sequence; null when the body has none. */
        public readonly ?int $sequence,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Present and true ONLY on rehearsal deliveries. */
        public readonly ?bool $test = null,
        /** The wire body as received. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'type'),
            Wire::str($data, 'uuid'),
            Wire::nullableInt($data, 'sequence'),
            Wire::bool($data, 'is_final'),
            Wire::nullableBool($data, 'test'),
            $data,
        );
    }

    public function type(): string
    {
        return $this->type;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function sequence(): ?int
    {
        return $this->sequence;
    }

    public function isFinal(): bool
    {
        return $this->is_final;
    }

    public function isTest(): bool
    {
        return $this->test === true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
