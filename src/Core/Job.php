<?php

declare(strict_types=1);

namespace Oblodai\Core;

use BackedEnum;
use LogicException;
use Oblodai\Lro;
use RuntimeException;

/**
 * A long-running operation (a batch, a document export): its `id`, the create call's answer
 * `result`, and `wait()`, which polls until the status is one of the job's terminal values (the
 * contract's `x-sdk-poll`, {@see Lro::JOBS}) and returns the last poll answer — a terminal status is returned, not thrown, so a `failed` job
 * is inspected like a finished one.
 *
 * @template R the create call's answer
 * @template P the poll answer
 */
final class Job
{
    /** @var callable(): P */
    private $poll;

    /** @var (callable(): FileResult)|null */
    private $download;

    /** @var callable(float): void */
    private $sleep;

    /** @var list<string> */
    private readonly array $terminal;

    /**
     * @param R                           $result   the create call's answer, as the method returned it
     * @param callable(): P               $poll     one poll of the job
     * @param list<string>                $terminal the statuses that end the wait
     * @param (callable(): FileResult)|null $download the finished job's file, for jobs that make one
     * @param (callable(float): void)|null  $sleep    pauses between polls, seconds
     * @param string                      $statusField the status field of a poll answer
     */
    public function __construct(
        public readonly string $id,
        public readonly mixed $result,
        callable $poll,
        array $terminal,
        ?callable $download = null,
        ?callable $sleep = null,
        public readonly string $statusField = 'status',
    ) {
        if ($terminal === []) {
            throw new LogicException(sprintf('job %s has no terminal status to wait for', $id));
        }
        $this->poll = $poll;
        $this->terminal = $terminal;
        $this->download = $download;
        $this->sleep = $sleep ?? [Transport::class, 'realSleep'];
    }

    /**
     * Poll every `$interval` seconds until the job's status is terminal; return that answer.
     *
     * @return P
     *
     * @throws RuntimeException when `$timeout` seconds pass first
     */
    public function wait(float $timeout = 300.0, float $interval = 2.0): mixed
    {
        $started = microtime(true);
        $slept = 0.0;
        for (;;) {
            $answer = ($this->poll)();
            $status = self::statusOf($answer, $this->statusField);
            if (in_array($status, $this->terminal, true)) {
                return $answer;
            }
            // Sleeps count even when the sleeper is a test double that returns at once.
            $remaining = $timeout - max(microtime(true) - $started, $slept);
            if ($remaining <= 0) {
                throw new RuntimeException(sprintf(
                    'job %s is still %s after %ss',
                    $this->id,
                    $status !== '' ? $status : 'unfinished',
                    rtrim(rtrim(sprintf('%.3F', $timeout), '0'), '.')
                ));
            }
            $pause = max(0.0, min($interval, $remaining));
            ($this->sleep)($pause);
            $slept += $pause > 0 ? $pause : 0.001;
        }
    }

    /** The finished job's file (document jobs only). */
    public function download(): FileResult
    {
        if ($this->download === null) {
            throw new LogicException(sprintf('job %s produces no file to download', $this->id));
        }

        return ($this->download)();
    }

    /** The status (`$field`) of a poll answer, model or array. */
    public static function statusOf(mixed $answer, string $field = 'status'): string
    {
        $status = is_array($answer) ? ($answer[$field] ?? '') : (is_object($answer) ? (get_object_vars($answer)[$field] ?? '') : '');
        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return is_string($status) ? $status : '';
    }
}
