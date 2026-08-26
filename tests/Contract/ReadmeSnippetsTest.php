<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every `php` block in the READMEs has to be real PHP, and the Russian README has to show the very
 * same code as the English one.
 *
 * A snippet that no longer parses is a snippet nobody can paste: the fastest way for a README to
 * rot is for an option to be renamed and only the prose to follow. `php -l` catches the half of
 * that which is mechanical — a stale argument list, a dropped brace, a named argument written as an
 * array key — and the equality check keeps the translation from quietly drifting into a second,
 * unmaintained copy of the examples.
 *
 * PHPStan already analyses `examples/`, so the runnable programs are type-checked; this tier covers
 * the fragments that only ever live in the documentation.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const ENGLISH = 'README.md';

    private const RUSSIAN = 'README.ru.md';

    /** @return iterable<string, array{string}> */
    public static function snippets(): iterable
    {
        foreach ([self::ENGLISH, self::RUSSIAN] as $file) {
            foreach (self::phpBlocks($file) as $line => $code) {
                yield sprintf('%s:%d', $file, $line) => [$code];
            }
        }
    }

    #[DataProvider('snippets')]
    public function testSnippetIsSyntacticallyValidPhp(string $code): void
    {
        $path = tempnam(sys_get_temp_dir(), 'oblodai-readme-') . '.php';
        // The blocks are fragments, so they carry no opening tag of their own.
        file_put_contents($path, "<?php\n" . $code . "\n");

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);
        unlink($path);

        self::assertSame(0, $status, "php -l rejected a README snippet:\n" . implode("\n", $output));
    }

    /** The translation must show the same code, not a re-typed variant of it. */
    public function testRussianSnippetsAreIdenticalToTheEnglishOnes(): void
    {
        $english = array_values(self::phpBlocks(self::ENGLISH));
        $russian = array_values(self::phpBlocks(self::RUSSIAN));

        self::assertNotSame([], $english, 'README.md has no php snippets — the extractor is broken');
        self::assertSame($english, $russian, 'README.ru.md must repeat the English code blocks verbatim');
    }

    /**
     * The fenced ` ```php ` blocks of a README, keyed by the 1-based line the block starts on.
     *
     * @return array<int, string>
     */
    private static function phpBlocks(string $file): array
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('cannot read %s', $path));
        }

        $blocks = [];
        $buffer = [];
        $openedAt = null;
        foreach (explode("\n", $contents) as $index => $line) {
            if ($openedAt === null) {
                if (rtrim($line) === '```php') {
                    $openedAt = $index + 2;   // the first line of code
                    $buffer = [];
                }

                continue;
            }
            if (rtrim($line) === '```') {
                $blocks[$openedAt] = implode("\n", $buffer);
                $openedAt = null;

                continue;
            }
            $buffer[] = $line;
        }

        if ($openedAt !== null) {
            throw new RuntimeException(sprintf('%s has an unterminated php block at line %d', $file, $openedAt));
        }

        return $blocks;
    }
}
