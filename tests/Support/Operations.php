<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use Oblodai\Generated\Routes;
use RuntimeException;

/**
 * Which client method serves each `operationId`, and which model parses its answer — read from
 * the generated resources themselves, so a renamed method or model is noticed, never assumed.
 *
 * @phpstan-type Op array{resource: string, class: string, method: string, entry: string, parse: string|null}
 */
final class Operations
{
    /** @var array<string, Op>|null */
    private static ?array $index = null;

    /** @return array<string, Op> operationId => where it lives */
    public static function all(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Generated/Resource/*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            $source = (string) file_get_contents($file);
            // One generated method: its name, then (before the next method) its entry and route.
            $parts = preg_split('/\n    public function /', $source) ?: [];
            array_shift($parts);
            foreach ($parts as $part) {
                if (preg_match('/^(\w+)\(/', $part, $name) !== 1
                    || preg_match("/\\\$this->(request\\w*)\\(\\s*Routes::get\\('(\\w+)'\\)/", $part, $call) !== 1) {
                    continue;
                }
                $parse = preg_match('/parse: (\w+)::(?:fromArray|parse)\(/', $part, $p) === 1 ? $p[1] : null;
                $out[$call[2]] = [
                    'resource' => lcfirst($class),
                    'class' => $class,
                    'method' => $name[1],
                    'entry' => $call[1],
                    'parse' => $parse,
                ];
            }
        }
        ksort($out);

        return self::$index = $out;
    }

    /** @return Op */
    public static function get(string $operationId): array
    {
        return self::all()[$operationId] ?? throw new RuntimeException(sprintf('no generated method calls %s', $operationId));
    }

    /**
     * A minimal valid `result` for an operation's answer (a paged list gets one item).
     *
     * @param array<string, mixed> $overrides merged over the sample (over the item, for a list)
     */
    public static function sampleResult(string $operationId, array $overrides = []): mixed
    {
        $op = self::get($operationId);
        $model = $op['parse'] !== null ? 'Oblodai\\Generated\\Model\\' . $op['parse'] : null;
        $body = $model !== null ? Samples::of($model, $overrides) : $overrides;
        if (Routes::get($operationId)->listKind === 'paged') {
            return ['items' => [$body], 'paginate' => ['total' => 1, 'per_page' => 50, 'offset' => 0, 'has_pages' => false]];
        }

        return $body;
    }
}
