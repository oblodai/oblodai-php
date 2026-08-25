<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/transfer/to-personal`: business balance to the owner's personal balance. */
final class TransferToPersonal
{
    /** @var list<string> */
    public const KEYS = ['uuid', 'currency', 'amount', 'direction', 'personal_balance', 'document_url'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Transfer id. */
        public readonly string $uuid,
        /** Transfer currency code. */
        public readonly string $currency,
        /** Transferred amount, decimal string. */
        public readonly string $amount,
        /** Always `to_personal`. */
        public readonly string $direction,
        /** Personal balance after the transfer. */
        public readonly string $personal_balance,
        /** Signed link to the PDF document for this operation; empty when documents are disabled. */
        public readonly string $document_url,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'uuid'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'direction'),
            Wire::str($data, 'personal_balance'),
            Wire::str($data, 'document_url'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
