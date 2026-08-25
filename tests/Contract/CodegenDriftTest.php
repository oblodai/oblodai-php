<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `php scripts/codegen.php` regenerates `src/Contract/{Routes,Enums,Version}.php` and
 * `src/Contract/{Enum,Request}/*.php` from `contract/contract.json`. Running it against an
 * up-to-date checkout must be a no-op: if it isn't, either the generator drifted from what's
 * committed, or someone hand-edited a generated file. Either way this must fail loudly, not in CI
 * fifteen minutes later.
 */
final class CodegenDriftTest extends TestCase
{
    public function testCodegenLeavesSrcContractUnchanged(): void
    {
        $root = dirname(__DIR__, 2);
        $before = self::hashContractDir($root);

        $output = [];
        $exitCode = 0;
        exec(sprintf('php %s 2>&1', escapeshellarg($root . '/scripts/codegen.php')), $output, $exitCode);
        self::assertSame(0, $exitCode, sprintf("scripts/codegen.php exited %d:\n%s", $exitCode, implode("\n", $output)));

        $after = self::hashContractDir($root);
        self::assertSame(
            $before,
            $after,
            'php scripts/codegen.php changed files under src/Contract — regenerate and commit them '
                . '(composer codegen), or contract/contract.json and the checked-in generated files have drifted'
        );
    }

    /** @return array<string, string> file path relative to src/Contract => sha256 of its contents */
    private static function hashContractDir(string $root): array
    {
        $dir = $root . '/src/Contract';
        /** @var array<string, string> $hashes */
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen($dir) + 1);
            $hashes[$relative] = (string) hash_file('sha256', $path);
        }
        ksort($hashes);

        return $hashes;
    }
}
