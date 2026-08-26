<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearerResult;

/** How a fee was settled on a priced result. `fee_type` is the pricing mode (`percent`/`fixed`/…). */
final class FeeInfo
{
    /** @var list<string> */
    public const KEYS = ['commission', 'fee_bearer', 'fee_type'];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<FeeBearerResult> $fee_bearer
     */
    public function __construct(
        /** Fee amount actually charged. */
        public readonly string $commission,
        /** Who bore the fee: `recipient` | `merchant` | `gateway`. */
        public readonly OpenEnum $fee_bearer,
        /** Pricing mode for this fee (for example `percent`, `fixed`). */
        public readonly string $fee_type,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'commission'),
            Wire::enum(FeeBearerResult::class, $data, 'fee_bearer'),
            Wire::str($data, 'fee_type'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
