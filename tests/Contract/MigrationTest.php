<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MIGRATION-2.0.md names every 2.0 method — the frozen list `names.2.0.txt`, written once at 2.0,
 * not the living `names.lock` (which the generator extends as the contract grows) — and no 2.0 name
 * has left the lock since.
 */
final class MigrationTest extends TestCase
{
    public function testTheGuideNamesExactlyTheMethodsOf20(): void
    {
        $frozen = self::names('names.2.0.txt');
        self::assertCount(120, $frozen, 'names.2.0.txt is the 2.0 surface and never changes');

        $guide = self::read('MIGRATION-2.0.md');
        $start = strpos($guide, "\n## Method names\n");
        $end = strpos($guide, "\n## Arguments\n");
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $section = substr($guide, $start, $end - $start);

        $named = [];
        foreach (explode("\n", $section) as $row) {
            // | `1.x` | `resource->method()` [/ method() …] | route |
            if (preg_match('/^\| `[^`]+` \| (.+?) \| `[A-Z]+ [^`]+` \|$/', $row, $m) === 1
                && preg_match('/`(\w+)->/', $m[1], $resource) === 1) {
                preg_match_all('/(\w+)\(\)/', $m[1], $methods);
                foreach ($methods[1] as $method) {
                    $named[self::snake($resource[1]) . '.' . self::snake($method)] = true;
                }
            }
        }
        $new = strpos($section, 'New in 2.0');
        self::assertNotFalse($new);
        preg_match_all('/`(\w+)->(\w+)\(\)`/', substr($section, $new), $m, PREG_SET_ORDER);
        foreach ($m as [, $resource, $method]) {
            $named[self::snake($resource) . '.' . self::snake($method)] = true;
        }
        $named = array_keys($named);
        sort($named);

        self::assertSame($frozen, $named);
    }

    public function testNo20NameHasLeftTheLock(): void
    {
        self::assertSame([], array_values(array_diff(self::names('names.2.0.txt'), self::names('names.lock'))));
    }

    /** @return list<string> */
    private static function names(string $file): array
    {
        $names = array_values(array_filter(array_map('trim', explode("\n", self::read($file)))));
        sort($names);

        return $names;
    }

    private static function snake(string $camel): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }

    private static function read(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        if ($text === false) {
            throw new RuntimeException('cannot read ' . $file);
        }

        return $text;
    }
}
