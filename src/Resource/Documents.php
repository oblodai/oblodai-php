<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\DocumentJob;
use Oblodai\Contract\Request\DocumentsJobsRequest;
use Oblodai\Core\FileResult;
use Oblodai\Core\RequestOptions;

/**
 * Generated PDF/CSV documents. Every method returns the bytes (`FileResult`); large ranges go
 * through asynchronous jobs (`createJob` → `jobInfo` → `jobFile`).
 *
 * The `$query` arrays accept `lang` (2-letter code, 41 supported), `format` (`pdf`|`csv`) where the
 * document offers CSV, and `from`/`to` (`YYYY-MM-DD`) where it covers a period.
 */
final class Documents extends Resource
{
    /**
     * `POST /v1/documents/jobs` — queue a large report; poll `jobInfo()`, then `jobFile()`.
     *
     * @param array<string, mixed>|DocumentsJobsRequest $params
     */
    public function createJob(array|DocumentsJobsRequest $params, ?RequestOptions $options = null): DocumentJob
    {
        return $this->call('POST /v1/documents/jobs', $params, $options, DocumentJob::fromArray(...));
    }

    /** `POST /v1/documents/jobs/info`. */
    public function jobInfo(string $jobId, ?RequestOptions $options = null): DocumentJob
    {
        return $this->call(
            'POST /v1/documents/jobs/info',
            ['job_id' => $jobId],
            $options,
            DocumentJob::fromArray(...)
        );
    }

    /** `GET /v1/documents/jobs/file` — the finished job's bytes. */
    public function jobFile(string $jobId, ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/jobs/file', ['job_id' => $jobId], [], null, $options);
    }

    /**
     * `GET /v1/documents/statement` — account statement for a period (PDF or CSV).
     *
     * @param array<string, mixed> $query
     */
    public function statement(array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/statement', $query, [], null, $options);
    }

    /**
     * `GET /v1/documents/balance` — balance certificate (PDF).
     *
     * @param array<string, mixed> $query
     */
    public function balanceCertificate(array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/balance', $query, [], null, $options);
    }

    /**
     * `GET /v1/documents/fees` — the fee schedule in force for the merchant (PDF).
     *
     * @param array<string, mixed> $query
     */
    public function feeSchedule(array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/fees', $query, [], null, $options);
    }

    /**
     * `GET /v1/documents/ledger` — full ledger export for a period (PDF or CSV).
     *
     * @param array<string, mixed> $query
     */
    public function ledger(array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/ledger', $query, [], null, $options);
    }

    /**
     * `GET /v1/documents/split` — how one payment was split between partners (PDF).
     *
     * @param array<string, mixed> $query
     */
    public function splitReport(
        string $paymentUuid,
        array $query = [],
        ?RequestOptions $options = null,
    ): FileResult {
        return $this->file('GET /v1/documents/split', array_merge($query, ['uuid' => $paymentUuid]), [], null, $options);
    }

    /**
     * `GET /v1/documents/batch` — per-row report of an asynchronous batch.
     *
     * @param array<string, mixed> $query
     */
    public function batchReport(string $batchId, array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/batch', array_merge($query, ['uuid' => $batchId]), [], null, $options);
    }

    /**
     * `GET /v1/documents/link` — payment-link report (its invoices).
     *
     * @param array<string, mixed> $query
     */
    public function linkReport(string $linkId, array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/link', array_merge($query, ['uuid' => $linkId]), [], null, $options);
    }

    /**
     * `GET /v1/documents/wallet/statement` — static-wallet statement.
     *
     * @param array<string, mixed> $query
     */
    public function walletStatement(
        string $walletUuid,
        array $query = [],
        ?RequestOptions $options = null,
    ): FileResult {
        return $this->file(
            'GET /v1/documents/wallet/statement',
            array_merge($query, ['uuid' => $walletUuid]),
            [],
            null,
            $options
        );
    }

    /**
     * `GET /v1/documents/referrals` — referral earnings report.
     *
     * @param array<string, mixed> $query
     */
    public function referralsReport(array $query = [], ?RequestOptions $options = null): FileResult
    {
        return $this->file('GET /v1/documents/referrals', $query, [], null, $options);
    }

    /**
     * `GET /v1/documents/{kind}/{id}` — a public document by its signed link (`exp` and `sig` come
     * from a `document_url`). No credentials needed; prefer fetching `document_url` directly.
     *
     * @param array<string, mixed> $query must carry `exp` and `sig`
     */
    public function download(
        string $kind,
        string $id,
        array $query,
        ?RequestOptions $options = null,
    ): FileResult {
        return $this->file(
            'GET /v1/documents/{kind}/{id}',
            $query,
            ['kind' => $kind, 'id' => $id],
            null,
            $options
        );
    }
}
