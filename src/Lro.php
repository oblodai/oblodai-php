<?php

declare(strict_types=1);

namespace Oblodai;

use Oblodai\Generated\Facts;

/**
 * Which operations are long-running, and how to follow them — the contract's `x-sdk-poll`, read
 * from the generated {@see Facts}. A create call listed in {@see Lro::LRO} can be wrapped in a
 * {@see Core\Job}:
 *
 * ```php
 * $job = $oblodai->batches->asJob(fn (Batches $b) => $b->createPayout($batch));
 * $info = $job->wait();          // polls getBatchInfo until the status is terminal
 * ```
 *
 * @phpstan-import-type JobPlan from Facts
 */
final class Lro
{
    /** `create operationId => poll operationId`. */
    public const LRO = Facts::LRO;

    /**
     * `create operationId => how to follow it`: the poll, the job's id field (sent under the same
     * name to the poll and the download), the status field and its terminal values, the generated
     * model of the poll answer, and the `operationId` of the finished job's file, if it makes one.
     *
     * @var array<string, JobPlan>
     */
    public const JOBS = Facts::JOBS;

    private function __construct()
    {
    }

    /**
     * How to follow the create call `$operationId`; null when it is not a long-running operation.
     * Read the table through here: the declared shape, not today's contents, is what code may rely on.
     *
     * @return JobPlan|null
     */
    public static function job(string $operationId): ?array
    {
        return self::JOBS[$operationId] ?? null;
    }
}
