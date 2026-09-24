<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use LogicException;
use Oblodai\Core\Job;
use Oblodai\Core\RequestOptions;
use Oblodai\Exception\ConfigException;
use Oblodai\Generated\Model\BatchInfoResponse;
use Oblodai\Generated\Model\BatchSubmitResponse;
use Oblodai\Generated\Model\DocumentJobView;
use Oblodai\Generated\Resource\Batches;
use Oblodai\Generated\Resource\Documents;
use Oblodai\Generated\Resource\Payments;
use Oblodai\Lro;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use Oblodai\Tests\Support\Operations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Long-running operations wait with `asJob(...)->wait()` (spec §3 item 8, table {@see Lro}). */
final class JobTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk', 'secret' => 's', 'baseUrl' => 'https://api.test'];

    public function testABatchJobPollsItsInfoUntilTheStatusIsTerminal(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::sample('createTransferBatch', ['batch_id' => 'b-1']),
            FakeHttpClient::sample('getBatchInfo', ['batch_id' => 'b-1', 'status' => 'processing']),
            FakeHttpClient::sample('getBatchInfo', ['batch_id' => 'b-1', 'status' => 'completed']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $job = $ob->payouts->asJob(static fn ($p) => $p->createTransferBatch(['transfers' => []]));
        self::assertInstanceOf(Job::class, $job);
        self::assertSame('b-1', $job->id);
        self::assertInstanceOf(BatchSubmitResponse::class, $job->result);

        $done = $job->wait(timeout: 5, interval: 0);

        self::assertInstanceOf(BatchInfoResponse::class, $done);
        self::assertSame('completed', Job::statusOf($done));
        self::assertSame(3, $fake->count());
        self::assertSame(['batch_id' => 'b-1'], $fake->body(1));
        self::assertNull($fake->header(1, 'Idempotency-Key'), 'polls are reads');
        self::assertNotSame($fake->header(1, 'X-Request-ID'), $fake->header(2, 'X-Request-ID'));
    }

    public function testEveryListedOperationIsACreateWhoseAnswerCarriesTheJobId(): void
    {
        foreach (Lro::LRO as $create => $poll) {
            $plan = Lro::POLLS[$poll];
            self::assertArrayHasKey($create, Operations::all());
            self::assertArrayHasKey($poll, Operations::all());
            $answer = Operations::sampleResult($create);
            self::assertIsArray($answer);
            self::assertArrayHasKey($plan['idField'], $answer, $create . ' answers with ' . $plan['idField']);
            self::assertTrue(class_exists('Oblodai\\Generated\\Model\\' . $plan['model']), $plan['model']);
            if ($plan['download'] !== null) {
                self::assertSame('requestFile', Operations::get($plan['download'])['entry']);
            }
        }
    }

    public function testADocumentJobDownloadsItsFileWhenDone(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::sample('createDocumentJob', ['job_id' => 'j-1']),
            FakeHttpClient::sample('getDocumentJob', ['job_id' => 'j-1', 'status' => 'done']),
            FakeHttpClient::raw(200, '%PDF-1.7', ['content-type' => 'application/pdf']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $job = $ob->documents->asJob(static fn (Documents $d) => $d->createJob(['kind' => 'statement']));
        self::assertInstanceOf(DocumentJobView::class, $job->wait(interval: 0));
        $file = $job->download();

        self::assertSame('%PDF-1.7', $file->bytes);
        self::assertStringContainsString('/v1/documents/jobs/file?job_id=j-1', $fake->calls[2]->url);
    }

    public function testPollsKeepTheCreateTimeoutButNotItsKey(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::sample('createPaymentBatch', ['batch_id' => 'b-2']),
            FakeHttpClient::sample('getBatchInfo', ['status' => 'stopped']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $job = $ob->batches->asJob(static fn (Batches $b) => $b->createPayment(
            ['payments' => []],
            new RequestOptions(idempotencyKey: 'batch-key', timeout: 7, extraHeaders: ['X-Shop' => 's1'])
        ));
        $job->wait(interval: 0);

        self::assertSame('batch-key', $fake->header(0, 'Idempotency-Key'));
        self::assertNull($fake->header(1, 'Idempotency-Key'));
        self::assertEqualsWithDelta(7.0, $fake->timeouts[1], 0.001);
        self::assertSame('s1', $fake->header(1, 'X-Shop'));
    }

    public function testWaitGivesUpAfterItsTimeout(): void
    {
        $slept = [];
        $job = new Job('j', null, static fn (): array => ['status' => 'running'], null, static function (float $s) use (&$slept): void {
            $slept[] = $s;
        });

        try {
            $job->wait(timeout: 3, interval: 1);
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('job j is still running after 3s', $e->getMessage());
        }
        self::assertSame([1.0, 1.0, 1.0], $slept);
    }

    public function testAJobWithoutAFileHasNothingToDownload(): void
    {
        $job = new Job('b', null, static fn (): array => ['status' => 'completed']);

        $this->expectException(LogicException::class);
        $job->download();
    }

    public function testAnOrdinaryCallIsNotAJob(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('createPayment')]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->payments->asJob(static fn (Payments $p) => $p->create(['amount' => '1', 'currency' => 'USDT']));
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('sdk.lro_unresolved', $e->errorCode);
        }
    }
}
