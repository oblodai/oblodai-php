<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/merchants` — a freshly provisioned merchant and its keys. */
final class MerchantOnboarded
{
    /** @var list<string> */
    public const KEYS = ['merchant_id', 'project_id', 'api_key', 'payment_key', 'payout_key'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The merchant's id. */
        public readonly string $merchant_id,
        /** The default project's id. */
        public readonly string $project_id,
        /** The unified key (same as `payment_key`/`payout_key` for merchants created now). */
        public readonly ApiKeyPair $api_key,
        /** Key for payment-side calls. */
        public readonly ApiKeyPair $payment_key,
        /** Key for payout-side calls. */
        public readonly ApiKeyPair $payout_key,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'merchant_id'),
            Wire::str($data, 'project_id'),
            ApiKeyPair::fromArray(Wire::obj($data, 'api_key')),
            ApiKeyPair::fromArray(Wire::obj($data, 'payment_key')),
            ApiKeyPair::fromArray(Wire::obj($data, 'payout_key')),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
