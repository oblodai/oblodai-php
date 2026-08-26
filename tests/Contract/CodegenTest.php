<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The generator's own guarantees. `safe` comes from the core and from nowhere else: the old
 * path-suffix heuristic guessed which routes were read-only, and a heuristic that guesses wrong
 * re-sends a payout after a timeout.
 */
final class CodegenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/scripts/codegen/emit.php';
    }

    public function testRouteSafetyIsTakenVerbatimFromTheContract(): void
    {
        self::assertTrue(routeIsSafe(['method' => 'POST', 'path' => '/v1/payout/history', 'safe' => true]));
        self::assertFalse(routeIsSafe(['method' => 'POST', 'path' => '/v1/payout', 'safe' => false]));
        // A path that reads read-only but is declared unsafe stays unsafe — no suffix matching.
        self::assertFalse(routeIsSafe(['method' => 'GET', 'path' => '/v1/anything/info', 'safe' => false]));
    }

    public function testCodegenRefusesARouteWithoutTheSafeField(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has no boolean `safe` field/');

        routeIsSafe(['method' => 'POST', 'path' => '/v1/payout']);
    }

    public function testCodegenRefusesANonBooleanSafeField(): void
    {
        $this->expectException(RuntimeException::class);

        routeIsSafe(['method' => 'POST', 'path' => '/v1/payout', 'safe' => 'yes']);
    }

    /** Every route in the shipped contract carries the field, so the gate above cannot fire in CI. */
    public function testEveryContractRouteDeclaresSafety(): void
    {
        $routes = (array) Fixtures::contract()['routes'];
        self::assertNotSame([], $routes);
        foreach ($routes as $route) {
            self::assertIsArray($route);
            $method = $route['method'] ?? null;
            $path = $route['path'] ?? null;
            self::assertIsString($method);
            self::assertIsString($path);
            self::assertIsBool($route['safe'] ?? null, sprintf('%s %s has no boolean `safe`', $method, $path));
        }
    }

    /** Generated code is English: an example copied from the core's Russian docs never lands in it. */
    public function testGeneratedFilesCarryNoNonAsciiExampleValues(): void
    {
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Contract/Request/*.php') as $path) {
            $source = (string) file_get_contents((string) $path);
            preg_match_all('/^\s*\*\s*Example: (.*)$/m', $source, $matches);
            foreach ($matches[1] as $example) {
                self::assertSame(
                    strlen($example),
                    mb_strlen($example, 'UTF-8'),
                    sprintf('%s: non-ASCII example %s', basename((string) $path), $example)
                );
            }
        }
    }
}
