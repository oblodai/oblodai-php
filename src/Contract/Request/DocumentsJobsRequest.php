<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/documents/jobs`.
 */
final class DocumentsJobsRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Report type: statement (operations), fees (commissions) or ledger (balance movements).
         * Example: "statement".
         */
        public readonly string $kind,
        /**
         * File format: pdf (default) or csv. CSV is generated without layout — cheaper for large statements and loads into Excel/1C.
         * Example: "csv".
         */
        public readonly ?string $format = null,
        /**
         * Start of the period, YYYY-MM-DD (defaults to the first day of the current month).
         * Example: "2025-01-01".
         */
        public readonly ?string $from = null,
        /**
         * Document language (default en).
         * Example: "ru".
         */
        public readonly ?string $lang = null,
        /**
         * End of the period, inclusive, YYYY-MM-DD (defaults to today). The period may span up to two years.
         * Example: "2026-08-19".
         */
        public readonly ?string $to = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'kind' => $this->kind,
            'format' => $this->format,
            'from' => $this->from,
            'lang' => $this->lang,
            'to' => $this->to,
        ]);
    }
}
