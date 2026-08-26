<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/documents/jobs/info`.
 */
final class DocumentsJobsInfoRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Job id from the creation response.
         * Example: "6f1c9e2a-...".
         */
        public readonly string $job_id,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'job_id' => $this->job_id,
        ]);
    }
}
