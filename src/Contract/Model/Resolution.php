<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/**
 * `POST /v1/payment/resolve` — a union of {@see ResolutionAccepted} (`resolution: "accepted"`) and
 * a {@see Payout} carrying `resolution: "refunded"`. The reference has no single flat key set for
 * this union (only `ResolutionAcceptedKeys` is exported), so this wrapper has no `KEYS` of its
 * own — validate the branch you decoded via `ResolutionAccepted::KEYS` or `Payout::KEYS` instead.
 */
final class Resolution
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        /** `accepted` | `refunded`. */
        public readonly string $resolution,
        /** Set when `resolution` is `accepted`. */
        public readonly ?ResolutionAccepted $accepted,
        /** Set when `resolution` is `refunded`: the refund payout that was issued. */
        public readonly ?Payout $refund,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $resolution = Wire::str($data, 'resolution');

        return new self(
            $resolution,
            $resolution === 'accepted' ? ResolutionAccepted::fromArray($data) : null,
            $resolution === 'refunded' ? Payout::fromArray($data) : null,
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
