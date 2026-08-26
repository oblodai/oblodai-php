<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Contract\Enums;
use Oblodai\Contract\Model\PaymentEvent;
use Oblodai\Contract\Model\PayoutEvent;
use Oblodai\Contract\Model\WalletEvent;
use Oblodai\Exception\ApiException;
use Oblodai\Exception\AuthenticationException;
use Oblodai\Exception\ConflictException;
use Oblodai\Exception\IdempotencyConflictException;
use Oblodai\Exception\InternalException;
use Oblodai\Exception\NotFoundException;
use Oblodai\Exception\PermissionException;
use Oblodai\Exception\RateLimitException;
use Oblodai\Exception\UnavailableException;
use Oblodai\Exception\ValidationException;
use Oblodai\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ports sdk-node's `test/contract/models.test.ts`: for every recorded success fixture, the matching
 * model decodes it and its key set equals the fixture's — plus the completeness gate ("every
 * recorded success body is covered by a model row"), the enum-vocabulary checks, the error-sample
 * checks, and "recorded request bodies only use documented fields".
 */
final class ModelsTest extends TestCase
{
    /** @return iterable<string, array{string, callable(array<string, mixed>): mixed, list<string>, list<string>}> */
    public static function rows(): iterable
    {
        foreach (ModelRows::rows() as $i => $row) {
            yield sprintf('%s #%d', $row[0], $i) => $row;
        }
    }

    /**
     * @param callable(array<string, mixed>): mixed $pick
     * @param list<string>                          $keys
     * @param list<string>                           $optional
     */
    #[DataProvider('rows')]
    public function testWireModelMatchesTheGoldenBody(string $route, callable $pick, array $keys, array $optional): void
    {
        $fixture = Fixtures::get($route);
        $status = $fixture['status'] ?? 0;
        if (!is_int($status) || $status < 200 || $status >= 300) {
            self::markTestSkipped(sprintf('%s: recorded as a refusal in this environment', $route));
        }

        /** @var array<string, mixed> $response */
        $response = $fixture['response'] ?? [];
        $rawResult = $response['result'] ?? null;
        self::assertIsArray($rawResult, sprintf('%s: no object result recorded', $route));
        /** @var array<string, mixed> $result */
        $result = $rawResult;

        $rawObject = $pick($result);
        self::assertIsArray($rawObject, sprintf('%s: picker found nothing', $route));
        self::assertNotSame([], $rawObject, sprintf('%s: picker found an empty object', $route));
        /** @var array<string, mixed> $object */
        $object = $rawObject;

        $actual = array_keys($object);
        $expectedSet = $keys;
        $missingOnWire = array_values(array_diff(array_diff($expectedSet, $actual), $optional));
        $unknownOnWire = array_values(array_diff(array_diff($actual, $expectedSet), $optional));
        self::assertSame([], $missingOnWire, sprintf('%s: fields the model expects but the wire omitted', $route));
        self::assertSame([], $unknownOnWire, sprintf('%s: fields the wire sent but the model does not expect', $route));
    }

    public function testEveryRecordedSuccessBodyIsCoveredByAModelRow(): void
    {
        /** @var array<string, bool> $covered */
        $covered = [];
        foreach (ModelRows::rows() as $row) {
            $covered[$row[0]] = true;
        }
        $notModelled = ModelRows::notModelled();

        foreach (Fixtures::all() as $route => $fixture) {
            $status = $fixture['status'] ?? 0;
            if (!is_int($status) || $status < 200 || $status >= 300 || isset($notModelled[$route])) {
                continue;
            }
            $headers = $fixture['headers'] ?? [];
            $rawContentType = is_array($headers) ? ($headers['Content-Type'] ?? null) : null;
            $contentType = is_string($rawContentType) ? $rawContentType : '';
            if (!str_contains($contentType, 'json')) {
                continue;
            }
            self::assertArrayHasKey($route, $covered, sprintf('%s: recorded success body has no model row', $route));
        }
    }

    public function testStatusesInFixturesAreInTheVocabulary(): void
    {
        foreach (self::itemsOf('POST /v1/payment/history') as $payment) {
            self::assertContains($payment['status'] ?? null, Enums::PAYMENT_STATUSES);
        }
        foreach (self::itemsOf('POST /v1/payout/history') as $payout) {
            self::assertContains($payout['status'] ?? null, Enums::PAYOUT_STATUSES);
        }
        foreach (self::itemsOf('POST /v1/payout/link/list') as $link) {
            self::assertContains($link['status'] ?? null, Enums::PAYOUT_LINK_STATUSES);
        }
        foreach (self::itemsOf('POST /v1/webhooks/deliveries') as $delivery) {
            self::assertContains($delivery['status'] ?? null, Enums::DELIVERY_STATUSES);
        }
    }

    /**
     * The `items` array of a recorded paged-list result.
     *
     * @return list<array<string, mixed>>
     */
    private static function itemsOf(string $route): array
    {
        $items = Fixtures::resultArray($route)['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    public function testWebhookSamplesCarryKnownEventTypesAndBodiesMatchingTheEventModels(): void
    {
        foreach (Fixtures::webhookSamples() as $sample) {
            /** @var array<string, mixed> $headers */
            $headers = $sample['headers'];
            /** @var array<string, mixed> $body */
            $body = $sample['body'];
            self::assertContains($headers['X-Webhook-Event'], Enums::EVENT_TYPES);

            $type = $body['type'] ?? null;
            $keys = match ($type) {
                'payment' => PaymentEvent::KEYS,
                'payout' => PayoutEvent::KEYS,
                'wallet' => WalletEvent::KEYS,
                default => self::fail(sprintf(
                    'unknown webhook body type "%s"',
                    is_scalar($type) ? (string) $type : get_debug_type($type)
                )),
            };
            $actual = array_keys($body);
            // `test` rides only on rehearsal deliveries, so it is optional on every event body.
            $optional = ['test'];
            self::assertSame([], array_values(array_diff($keys, $actual)), 'webhook sample: missing keys');
            self::assertSame(
                [],
                array_values(array_diff($actual, $keys, $optional)),
                'webhook sample: unexpected keys'
            );
        }
    }

    public function testEveryRecordedErrorCodeIsKnownWithTheDocumentedEnvelope(): void
    {
        foreach (Fixtures::errors() as $code => $fixture) {
            self::assertContains($code, Enums::ERROR_CODES);

            /** @var array<string, mixed> $response */
            $response = $fixture['response'] ?? [];
            /**
             * @var array{
             *     code?: string, message?: string, field?: string, retryable?: bool,
             *     retry_after?: int|float|string, request_id?: string
             * } $error
             */
            $error = $response['error'] ?? [];
            self::assertSame($code, $error['code'] ?? null);
            self::assertIsBool($error['retryable'] ?? null);
            self::assertIsString($error['request_id'] ?? null);
            $rawStatus = $fixture['status'] ?? null;
            $status = is_int($rawStatus) ? $rawStatus : 0;
            if ($status === 429) {
                self::assertGreaterThan(0, $error['retry_after'] ?? 0);
            }

            $exception = ApiException::from($status, $error);
            self::assertInstanceOf(self::expectedExceptionClass($status, $code), $exception);
            self::assertSame((bool) ($error['retryable'] ?? false), $exception->retryable);
        }
    }

    public function testRecordedRequestBodiesOnlyUseDocumentedFields(): void
    {
        /** @var array<string, array<string, mixed>|null> $schemas */
        $schemas = [];
        foreach ((array) Fixtures::contract()['routes'] as $route) {
            /** @var array{method: string, path: string, request_schema?: array<string, mixed>} $route */
            $schemas[$route['method'] . ' ' . $route['path']] = $route['request_schema'] ?? null;
        }

        foreach (Fixtures::all() as $route => $fixture) {
            $schema = $schemas[$route] ?? null;
            $properties = is_array($schema) ? ($schema['properties'] ?? null) : null;
            $request = $fixture['request'] ?? null;
            if (!is_array($properties) || !is_array($request)) {
                continue;
            }
            foreach (array_keys($request) as $field) {
                self::assertArrayHasKey(
                    $field,
                    $properties,
                    sprintf('%s: journey sent undocumented field "%s"', $route, $field)
                );
            }
        }
    }

    /** @return class-string<ApiException> */
    private static function expectedExceptionClass(int $status, string $code): string
    {
        return match (true) {
            $code === 'idempotency.key_reused' => IdempotencyConflictException::class,
            $status === 400 => ValidationException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status === 503 => UnavailableException::class,
            $status >= 500 => InternalException::class,
            default => ApiException::class,
        };
    }
}
