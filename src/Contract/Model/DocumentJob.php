<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/documents/jobs` and `/v1/documents/jobs/info`. */
final class DocumentJob
{
    /** Wire keys every rendering of a job carries. @var list<string> */
    public const KEYS = ['job_id', 'kind', 'format', 'lang', 'status', 'period', 'created_at', 'updated_at'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** This job's id. */
        public readonly string $job_id,
        /** What the report is about (for example, `statement`). */
        public readonly string $kind,
        /** File format of the finished report. */
        public readonly string $format,
        /** Language the report is rendered in. */
        public readonly string $lang,
        /** `queued`, `processing`, `done` or `failed`. */
        public readonly string $status,
        /** Date range the report covers. */
        public readonly DocumentJobPeriod $period,
        /** When the job was created (RFC 3339). */
        public readonly string $created_at,
        /** Time of the last status change (RFC 3339). */
        public readonly string $updated_at,
        /** Human hint while queued (for example, "15s"). */
        public readonly ?string $ready_within = null,
        /** Set once done; `download_url` is a signed link, or use `documents.jobFile`. */
        public readonly ?DocumentJobFile $file = null,
        /** Failure reason, once the job has failed. */
        public readonly ?string $error = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $file = Wire::obj($data, 'file');

        return new self(
            Wire::str($data, 'job_id'),
            Wire::str($data, 'kind'),
            Wire::str($data, 'format'),
            Wire::str($data, 'lang'),
            Wire::str($data, 'status'),
            DocumentJobPeriod::fromArray(Wire::obj($data, 'period')),
            Wire::str($data, 'created_at'),
            Wire::str($data, 'updated_at'),
            Wire::nullableStr($data, 'ready_within'),
            $file === [] ? null : DocumentJobFile::fromArray($file),
            Wire::nullableStr($data, 'error'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
