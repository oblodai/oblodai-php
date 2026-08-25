<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/transfer/to-user`: business balance to another user's personal balance. */
final class TransferToUser
{
    /** @var list<string> */
    public const KEYS = ['uuid', 'currency', 'amount', 'to_user_id', 'document_url'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Transfer id. */
        public readonly string $uuid,
        /** Transfer currency code. */
        public readonly string $currency,
        /** Transferred amount, decimal string. */
        public readonly string $amount,
        /** Id of the user credited with the transfer. */
        public readonly string $to_user_id,
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
            Wire::str($data, 'to_user_id'),
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
