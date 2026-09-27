<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Tests\Support\MockGateway;
use Oblodai\Tests\Support\Operations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The README code runs (spec §3 item 10): every ```php block of README.md executes, in order and in
 * one script the way a reader pastes them one after another, against the mock gateway — so a
 * renamed method, argument or field breaks this test. The Russian README must show the very same
 * code, and the method table must list exactly the client's methods.
 */
final class ReadmeTest extends TestCase
{
    public function testTheCodeBlocksRunInOrderAgainstTheMockGateway(): void
    {
        $blocks = self::phpBlocks('README.md');
        self::assertGreaterThan(10, count($blocks), 'the extractor found too few blocks');

        // `use` imports are hoisted (and deduplicated): PHP refuses the same import twice in a file.
        $uses = [];
        $code = [];
        foreach ($blocks as $line => $block) {
            $code[] = '// README.md:' . $line;
            foreach (explode("\n", $block) as $row) {
                if (preg_match('/^use [\w\\\\]+;$/', $row) === 1) {
                    $uses[$row] = true;
                } else {
                    $code[] = $row;
                }
            }
        }
        $script = "<?php\n\ndeclare(strict_types=1);\n\n" . implode("\n", array_keys($uses))
            . "\n\nrequire " . var_export(dirname(__DIR__) . '/Support/readme-prelude.php', true) . ";\n\n"
            . implode("\n", $code) . "\n\necho \"README ran to the end\\n\";\n";
        $path = tempnam(sys_get_temp_dir(), 'oblodai-readme-') . '.php';
        file_put_contents($path, $script);

        [$gateway, $baseUrl] = MockGateway::start('tests/Support/mock-gateway.php');

        try {
            [$code, $out, $err] = MockGateway::run([$path], [
                'OBLODAI_PUBLIC_ID' => 'test_oblodai_readme',
                'OBLODAI_SECRET' => str_repeat('s', 32),
                'OBLODAI_BASE_URL' => $baseUrl,
                'OBLODAI_ALLOW_INSECURE' => '1',
                'OBLODAI_WEBHOOK_SECRET' => 'whsec-readme',
            ]);
        } finally {
            MockGateway::stop($gateway);
            @unlink($path);
        }

        self::assertSame(0, $code, "the README code failed:\n" . $out . $err);
        self::assertStringEndsWith("README ran to the end\n", $out, $err);
        self::assertStringContainsString('marked order-1001 paid', $out, 'the webhook block verified the delivery');
    }

    /** The translation must show the same code, not a re-typed variant of it. */
    public function testTheRussianReadmeShowsTheSameCode(): void
    {
        self::assertSame(array_values(self::phpBlocks('README.md')), array_values(self::phpBlocks('README.ru.md')));
    }

    /** The method table is not a second copy of the truth: it must be the client's methods. */
    public function testTheMethodTableListsExactlyTheClientsMethods(): void
    {
        $surface = [];
        foreach (Operations::all() as $op) {
            $surface[$op['resource']][] = $op['method'];
        }
        foreach (['README.md', 'README.ru.md'] as $file) {
            $table = [];
            foreach (explode("\n", self::read($file)) as $row) {
                if (preg_match('/^\| `(\w+)` \| (`.+`) \|$/', $row, $m) === 1) {
                    preg_match_all('/`(\w+)`/', $m[2], $methods);
                    $table[$m[1]] = $methods[1];
                }
            }
            $want = $surface;
            ksort($want);
            ksort($table);
            foreach ($want as &$methods) {
                sort($methods);
            }
            unset($methods);
            foreach ($table as &$methods) {
                sort($methods);
            }
            unset($methods);

            self::assertSame($want, $table, $file);
        }
    }

    /** @return array<int, string> the fenced ```php blocks, keyed by the line the code starts on */
    private static function phpBlocks(string $file): array
    {
        $blocks = [];
        $buffer = [];
        $openedAt = null;
        foreach (explode("\n", self::read($file)) as $index => $line) {
            if ($openedAt === null) {
                if (rtrim($line) === '```php') {
                    $openedAt = $index + 2;
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

    private static function read(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        if ($text === false) {
            throw new RuntimeException('cannot read ' . $file);
        }

        return $text;
    }
}
