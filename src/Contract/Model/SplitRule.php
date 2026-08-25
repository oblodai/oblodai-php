<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/split/rule` and `/v1/split/rule/list` items. */
final class SplitRule
{
    /** Wire keys `/v1/split/rule/list` carries. @var list<string> */
    public const KEYS = ['rule_id', 'percent', 'active', 'address', 'network', 'note', 'reversible'];

    /** Wire keys `POST /v1/split/rule` answers with (id and percent only). @var list<string> */
    public const CREATED_KEYS = ['rule_id', 'percent'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** This rule's id. */
        public readonly string $rule_id,
        /** Share of every payment, percent, as a decimal string. */
        public readonly string $percent,
        /** True — the rule is currently applied. */
        public readonly ?bool $active = null,
        /** Where this share is paid out. */
        public readonly ?string $address = null,
        /** Network the payout is made on. */
        public readonly ?string $network = null,
        /** Set for on-platform partner rules (reversible on refund). */
        public readonly ?string $merchant_id = null,
        /** Your free-text label for the rule. */
        public readonly ?string $note = null,
        /** True — this rule's share is clawed back on a refund. */
        public readonly ?bool $reversible = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'rule_id'),
            Wire::str($data, 'percent'),
            Wire::nullableBool($data, 'active'),
            Wire::nullableStr($data, 'address'),
            Wire::nullableStr($data, 'network'),
            Wire::nullableStr($data, 'merchant_id'),
            Wire::nullableStr($data, 'note'),
            Wire::nullableBool($data, 'reversible'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
