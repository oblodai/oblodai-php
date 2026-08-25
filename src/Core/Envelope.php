<?php

declare(strict_types=1);

namespace Oblodai\Core;

use JsonException;
use Oblodai\Exception\ApiException;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\OblodaiException;

/**
 * Response envelopes, as `httpx`/`apiutil` on the core write them:
 *
 *   success : { "state": 0, "result": <payload> }
 *   list    : result = { "items": [...], "paginate": { total, per_page, offset, has_pages } }
 *   error   : { "error": { code, message, field?, retryable, retry_after?, request_id? } }
 *
 * Every non-`bare` route uses these; bare routes (PDF documents) bypass this module.
 */
final class Envelope
{
    /**
     * Interpret a response body. `$text` is the raw body so non-JSON failures keep their evidence.
     *
     * @return array{ok: true, result: mixed}|array{ok: false, error: OblodaiException}
     */
    public static function decode(
        int $httpStatus,
        string $text,
        ?string $retryAfterHeader = null,
        ?string $locationHeader = null,
    ): array {
        $retryAfter = self::parseRetryAfter($retryAfterHeader);

        if ($httpStatus >= 300 && $httpStatus < 400) {
            $where = $locationHeader !== null && $locationHeader !== '' ? ' to ' . $locationHeader : '';

            return ['ok' => false, 'error' => ApiException::from(
                $httpStatus,
                [
                    'code' => 'internal',
                    'message' => sprintf('unexpected redirect (HTTP %d)%s; check baseUrl', $httpStatus, $where),
                ],
                $text,
                true,
            )];
        }

        $body = null;
        $parsed = true;
        if ($text !== '') {
            try {
                $body = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $parsed = false;
            }
        }

        if (!$parsed) {
            if ($httpStatus >= 400) {
                return ['ok' => false, 'error' => ApiException::from(
                    $httpStatus,
                    ['code' => 'internal', 'message' => self::noEnvelope($httpStatus, $text)],
                    $text,
                    true,
                    $retryAfter,
                )];
            }

            throw new ContractException(
                sprintf('expected a JSON envelope, got %s', self::describe($text)),
                $httpStatus,
                $text
            );
        }

        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            /** @var array{code?: string, message?: string, field?: string, retryable?: bool, retry_after?: int|float|string, request_id?: string} $detail */
            $detail = $body['error'];

            return ['ok' => false, 'error' => ApiException::from($httpStatus, $detail, $body, false, $retryAfter)];
        }
        if ($httpStatus >= 400) {
            return ['ok' => false, 'error' => ApiException::from(
                $httpStatus,
                ['code' => 'internal', 'message' => self::noEnvelope($httpStatus, $text)],
                $body,
                true,
                $retryAfter,
            )];
        }
        if (is_array($body) && ($body['state'] ?? null) === 0 && array_key_exists('result', $body)) {
            return ['ok' => true, 'result' => $body['result']];
        }

        throw new ContractException(
            sprintf('response is not a {state:0,result} envelope: %s', self::describe($text)),
            $httpStatus,
            $body
        );
    }

    /** `Retry-After` as delta-seconds or an HTTP-date; null when absent or unparsable. */
    public static function parseRetryAfter(?string $value, ?int $now = null): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $v) === 1) {
            return (int) $v;
        }
        $at = strtotime($v);
        if ($at === false) {
            return null;
        }

        return max(0, $at - ($now ?? time()));
    }

    /**
     * Assert the paged-list shape on a decoded result.
     *
     * @return array{items: list<mixed>, paginate: Paginate}
     */
    public static function asPage(mixed $result, int $httpStatus = 200): array
    {
        if (is_array($result) && isset($result['items']) && is_array($result['items'])
            && isset($result['paginate']) && is_array($result['paginate'])) {
            /** @var array<string, mixed> $paginate */
            $paginate = $result['paginate'];

            return [
                'items' => array_values($result['items']),
                'paginate' => Paginate::fromArray($paginate),
            ];
        }

        throw new ContractException('expected {items, paginate} list result', $httpStatus, $result);
    }

    /**
     * Assert the plain-list shape (`{items}` without `paginate`).
     *
     * @return list<mixed>
     */
    public static function asPlainList(mixed $result, int $httpStatus = 200): array
    {
        if (is_array($result) && isset($result['items']) && is_array($result['items'])) {
            return array_values($result['items']);
        }

        throw new ContractException('expected {items} list result', $httpStatus, $result);
    }

    private static function noEnvelope(int $status, string $text): string
    {
        return sprintf(
            'HTTP %d without an Oblodai error envelope (%s) — the answer came from a proxy or load '
                . 'balancer, not the API',
            $status,
            self::describe($text)
        );
    }

    private static function describe(string $text): string
    {
        $head = (string) preg_replace('/\s+/', ' ', substr($text, 0, 120));

        return strlen($text) > 120 ? $head . '…' : ($head !== '' ? $head : '<empty body>');
    }
}
