<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Contract\Routes;
use Oblodai\Contract\RouteSpec;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use Oblodai\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ports sdk-node's `test/contract/routes.test.ts`: every route the core declares has exactly one
 * SDK method wired to the right METHOD + path + auth + idempotency header. `RouteCoverage::calls()`
 * is the map this test walks; a route without an entry there fails loudly (undefined array key).
 */
final class RoutesTest extends TestCase
{
    /** A route the API refuses to answer with a success body for an API key — no fixture exists. */
    private const NO_SUCCESS_FIXTURE = 'POST /v1/payout/approve';

    public function testRegistryIsExactlyTheContractsMerchantSurface(): void
    {
        /** @var array<string, bool> $declared */
        $declared = [];
        foreach ((array) Fixtures::contract()['routes'] as $route) {
            /** @var array{method: string, path: string} $route */
            if (preg_match('#^/(healthz|readyz|docs|openapi\.json|internal)#', $route['path']) === 1) {
                continue;
            }
            $declared[$route['method'] . ' ' . $route['path']] = true;
        }

        self::assertSame(self::sortedKeys($declared), self::sortedKeys(array_fill_keys(Routes::keys(), true)));
    }

    /**
     * Not just the key set: every FLAG of every route must equal what the core exported. `safe` in
     * particular decides whether a failed write may be re-sent, and it is the core's own
     * hand-classification — nothing in the SDK is allowed to infer it from the path.
     */
    public function testEveryRegistryRowEqualsTheContractFieldByField(): void
    {
        $checked = 0;
        foreach ((array) Fixtures::contract()['routes'] as $route) {
            self::assertIsArray($route);
            $method = $route['method'] ?? null;
            $path = $route['path'] ?? null;
            self::assertIsString($method);
            self::assertIsString($path);
            $key = $method . ' ' . $path;
            if (!isset(Routes::SPECS[$key])) {
                continue; // infrastructure endpoint, excluded from the merchant surface
            }
            self::assertArrayHasKey('safe', $route, $key . ': the contract must declare `safe`');
            self::assertSame(
                [],
                self::mismatches($route, Routes::SPECS[$key]),
                $key . ': generated registry disagrees with contract.json'
            );
            ++$checked;
        }
        self::assertSame(count(Routes::SPECS), $checked);
    }

    /**
     * The comparison above must actually be able to fail. Feed it a row with one flag flipped and
     * it has to name that flag — otherwise a drifted registry would ship green.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function flippedFields(): iterable
    {
        yield 'method' => ['method', 'GET'];
        yield 'path' => ['path', '/v1/elsewhere'];
        yield 'auth' => ['auth', 'public'];
        yield 'idempotent' => ['idempotent', false];
        yield 'safe' => ['safe', true];
        yield 'bare' => ['bare', true];
        yield 'list' => ['list', 'paged'];
    }

    #[DataProvider('flippedFields')]
    public function testTheFieldByFieldCheckCatchesAFlippedFlag(string $field, mixed $value): void
    {
        $key = 'POST /v1/payout';
        $spec = Routes::SPECS[$key];
        $contractRow = ['method' => 'POST', 'path' => '/v1/payout', 'auth' => 'key',
            'idempotent' => true, 'safe' => false, 'bare' => false];

        self::assertSame([], self::mismatches($contractRow, $spec), 'the unflipped row must match');

        $contractRow[$field] = $value;
        self::assertSame([$field], self::mismatches($contractRow, $spec));
    }

    /**
     * Fields of the contract row that the generated spec does not reproduce.
     *
     * @param  array<mixed>         $route
     * @param  array<string, mixed> $spec
     * @return list<string>
     */
    private static function mismatches(array $route, array $spec): array
    {
        $out = [];
        foreach (['method', 'path', 'auth', 'idempotent', 'safe', 'bare'] as $field) {
            if (($route[$field] ?? null) !== ($spec[$field] ?? null)) {
                $out[] = $field;
            }
        }
        if ((($route['list'] ?? null) ?: null) !== ($spec['list'] ?? null)) {
            $out[] = 'list';
        }

        return $out;
    }

    public function testEveryRecordedFixtureBelongsToAKnownRoute(): void
    {
        foreach (array_keys(Fixtures::all()) as $route) {
            self::assertArrayHasKey($route, Routes::SPECS, sprintf('fixture for unknown route "%s"', $route));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function routeKeys(): iterable
    {
        foreach (Routes::keys() as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('routeKeys')]
    public function testRouteHasAnSdkMethodWiredToIt(string $key): void
    {
        $spec = Routes::get($key);
        $coverage = RouteCoverage::calls();
        self::assertArrayHasKey($key, $coverage, sprintf('%s has no SDK method wired in RouteCoverage', $key));

        $fake = new FakeHttpClient([self::scriptedResponse($key, $spec)]);
        $ob = new Oblodai(
            publicId: 'pk',
            secret: 's',
            adminToken: 'adm',
            baseUrl: 'https://api.test',
            http: $fake,
            env: [],
        );

        $coverage[$key]($ob);

        self::assertSame(1, $fake->count(), sprintf('%s: expected exactly one request', $key));
        $call = $fake->calls[0];
        self::assertSame($spec->method, $call->method, sprintf('%s: method', $key));

        $path = (string) parse_url($call->url, PHP_URL_PATH);
        $pattern = '#^' . (string) preg_replace('/\{[a-zA-Z_]+\}/', '[^/]+', $spec->path) . '$#';
        self::assertMatchesRegularExpression($pattern, $path, sprintf('%s: path', $key));

        if ($spec->auth === 'public') {
            self::assertNull($fake->header(0, 'X-Signature'), sprintf('%s: public route must not sign', $key));
        } elseif ($spec->auth === 'onboard') {
            self::assertNull($fake->header(0, 'X-Signature'), sprintf('%s: onboard route must not sign', $key));
            self::assertSame('adm', $fake->header(0, 'X-Admin-Token'), sprintf('%s: admin token', $key));
        } else {
            self::assertSame('key', $spec->auth, sprintf('%s: unknown auth gate', $key));
            self::assertSame('pk', $fake->header(0, 'X-Public-Id'), sprintf('%s: public id', $key));
            self::assertNotNull($fake->header(0, 'X-Signature'), sprintf('%s: signed route', $key));
            self::assertNull(
                $fake->header(0, 'X-Admin-Token'),
                sprintf('%s: the admin token rides only on onboard routes', $key)
            );
        }

        if ($spec->idempotent) {
            self::assertNotNull($fake->header(0, 'Idempotency-Key'), sprintf('%s: expected an idempotency key', $key));
        } else {
            self::assertNull($fake->header(0, 'Idempotency-Key'), sprintf('%s: must not send an idempotency key', $key));
        }
    }

    /** @return array<string, mixed> one FakeHttpClient script entry */
    private static function scriptedResponse(string $key, RouteSpec $spec): array
    {
        if ($spec->bare) {
            return FakeHttpClient::raw(200, '%PDF', ['content-type' => 'application/pdf']);
        }
        if ($key === self::NO_SUCCESS_FIXTURE) {
            return FakeHttpClient::ok(self::genericPayout());
        }
        $fixture = Fixtures::get($key);
        $status = $fixture['status'] ?? 0;
        if (is_int($status) && $status >= 200 && $status < 300) {
            /** @var array<string, mixed> $response */
            $response = $fixture['response'] ?? [];

            return FakeHttpClient::ok($response['result'] ?? []);
        }

        // No recorded success body for this route (shouldn't happen for anything but the one
        // constant above) — fall back to a body generic enough for any model to decode.
        return FakeHttpClient::ok([
            'items' => [],
            'paginate' => ['total' => 0, 'per_page' => 1, 'offset' => 0, 'has_pages' => false],
            'enabled' => true,
        ]);
    }

    /**
     * A Payout-shaped body valid enough for `Payout::fromArray()` (status/fee_bearer are enums).
     *
     * @return array<string, mixed>
     */
    private static function genericPayout(): array
    {
        return [
            'uuid' => 'p1',
            'order_id' => 'o1',
            'status' => 'approved',
            'is_final' => false,
            'amount' => '1',
            'currency' => 'USDT',
            'network' => 'tron',
            'address' => 'T',
            'memo' => '',
            'payer_amount' => '1',
            'commission' => '0',
            'fee_bearer' => 'merchant',
            'source' => 'business',
            'approval_required' => false,
            'is_refund' => false,
            'refund_for' => null,
            'payment_order_id' => null,
            'txid' => '',
            'document_url' => '',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ];
    }

    /**
     * @param array<string, mixed> $map
     *
     * @return list<string>
     */
    private static function sortedKeys(array $map): array
    {
        $keys = array_keys($map);
        sort($keys);

        return $keys;
    }
}
