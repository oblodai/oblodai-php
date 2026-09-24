<?php

declare(strict_types=1);

namespace Oblodai;

/**
 * Which operations are long-running, and how to follow them — a decision of this SDK, not of the
 * API. A create call listed in {@see Lro::LRO} can be wrapped in a {@see Core\Job}:
 *
 * ```php
 * $job = $oblodai->batches->asJob(fn (Batches $b) => $b->createPayout($batch));
 * $info = $job->wait();          // polls getBatchInfo until the status is terminal
 * ```
 *
 * The runtime applies the table by the route's `operationId`; the generator knows nothing of it.
 */
final class Lro
{
    /** `create operationId => poll operationId`. */
    public const LRO = [
        'createPaymentBatch' => 'getBatchInfo',
        'createPayoutBatch' => 'getBatchInfo',
        'createRefundBatch' => 'getBatchInfo',
        'createTransferBatch' => 'getBatchInfo',
        'createDocumentJob' => 'getDocumentJob',
    ];

    /**
     * `poll operationId => how to follow it`: the job's id in the create answer (sent under the
     * same name to the poll and the download), the generated model of the poll answer, and the
     * `operationId` that returns the finished job's file, if the job makes one.
     *
     * @var array<string, array{idField: string, model: string, download: string|null}>
     */
    public const POLLS = [
        'getBatchInfo' => ['idField' => 'batch_id', 'model' => 'BatchInfoResponse', 'download' => null],
        'getDocumentJob' => ['idField' => 'job_id', 'model' => 'DocumentJobView', 'download' => 'downloadDocumentJobFile'],
    ];

    /**
     * Statuses after which a job no longer changes: a batch ends `completed` or `stopped`
     * (`on_error=stop`), a document job `done`, `failed` or `expired`.
     */
    public const TERMINAL_STATUSES = ['completed', 'stopped', 'done', 'failed', 'expired'];

    private function __construct()
    {
    }
}
