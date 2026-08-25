<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/payment/link` acknowledgement. */
final class PaymentLinkCreated
{
    /** @var list<string> */
    public const KEYS = ['link_id', 'url', 'document_url'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Link id. */
        public readonly string $link_id,
        /** Public URL of the payment page — give it to the buyer as a button, in an email or as a QR code. */
        public readonly string $url,
        /** Signed link to a PDF poster with the payment QR (for printing at the till). Empty if documents are disabled. */
        public readonly string $document_url,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'link_id'),
            Wire::str($data, 'url'),
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
