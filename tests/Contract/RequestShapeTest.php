<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Contract\Routes;
use Oblodai\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The generated request DTOs against the requests the core actually accepted.
 *
 * The core's docs table is hand-written and sometimes copies a `required` list from a neighbouring
 * route (`POST /v1/payout/validate` inherited `create`'s, including `order_id`). A DTO that demands
 * a field the gateway does not need makes callers invent values; this test catches it, because the
 * recorded fixture is a request that really answered 2xx.
 */
final class RequestShapeTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function recordedRequests(): iterable
    {
        foreach (Fixtures::all() as $route => $fixture) {
            $status = $fixture['status'] ?? 0;
            $request = $fixture['request'] ?? null;
            if (!is_int($status) || $status < 200 || $status >= 300 || !is_array($request)) {
                continue;
            }
            /** @var array<string, mixed> $request */
            yield $route => [$route, $request];
        }
    }

    /** @param array<string, mixed> $request */
    #[DataProvider('recordedRequests')]
    public function testNoDtoDemandsAFieldTheCoreAcceptedWithout(string $route, array $request): void
    {
        $class = self::dtoFor($route);
        if ($class === null || !class_exists($class)) {
            self::assertArrayHasKey($route, Routes::SPECS);

            return;
        }

        $missing = [];
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue; // optional in the DTO — nothing to prove
            }
            if (!array_key_exists($parameter->getName(), $request)) {
                $missing[] = $parameter->getName();
            }
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                '%s requires %s, but the recorded %s request omits them and the core answered 2xx — '
                    . 'add them to OPTIONAL_OVERRIDES in scripts/codegen/requests.php',
                $class,
                implode(', ', $missing),
                $route
            )
        );
    }

    /** `POST /v1/payout/validate` is the case that motivated the check; keep it pinned. */
    public function testPayoutValidateDoesNotRequireAnOrderId(): void
    {
        $class = self::dtoFor('POST /v1/payout/validate');
        self::assertNotNull($class);
        self::assertTrue(class_exists($class));

        $optional = [];
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $optional[] = $parameter->getName();
            }
        }

        self::assertContains('order_id', $optional);
        self::assertNotContains('address', $optional, 'an address is genuinely required');
    }

    /**
     * Every DTO field whose value is a decimal amount is typed `string`, never `float`: the one
     * `number` the contract declares is a percentage, and nothing else may become a double.
     */
    public function testNoDtoTypesAnAmountAsAFloat(): void
    {
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Contract/Request/*.php') as $path) {
            $class = 'Oblodai\\Contract\\Request\\' . basename((string) $path, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->getName() !== 'float') {
                    continue;
                }
                self::assertContains(
                    $parameter->getName(),
                    Routes::NUMBER_FIELDS,
                    sprintf('%s::$%s is a float but the contract does not declare it a number', $class, $parameter->getName())
                );
            }
        }
    }

    /** The route a generated DTO belongs to is recorded in its own docblock. */
    private static function dtoFor(string $route): ?string
    {
        /** @var array<string, string>|null $map */
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ((array) glob(dirname(__DIR__, 2) . '/src/Contract/Request/*.php') as $path) {
                $source = (string) file_get_contents((string) $path);
                if (preg_match('/Body of `([^`]+)`/', $source, $m) === 1) {
                    $map[$m[1]] = 'Oblodai\\Contract\\Request\\' . basename((string) $path, '.php');
                }
            }
        }

        return $map[$route] ?? null;
    }
}
