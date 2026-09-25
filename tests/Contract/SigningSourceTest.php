<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Core\Idempotency;
use Oblodai\Core\Signer;
use Oblodai\Generated\Signing;
use Oblodai\Webhook\Verifier;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The signing protocol has one source: the contract's `x-oblodai-signing`, generated into
 * {@see Signing}. The runtime's public names are aliases of it, and no hand-written file spells a
 * header name or a limit of its own — a header renamed in the contract reaches the SDK by
 * regeneration alone.
 */
final class SigningSourceTest extends TestCase
{
    public function testPublicNamesAreAliasesOfTheGeneratedProtocol(): void
    {
        self::assertSame(Signing::HEADER_PUBLIC_ID, Signer::HEADER_PUBLIC_ID);
        self::assertSame(Signing::HEADER_SIGNATURE, Signer::HEADER_SIGNATURE);
        self::assertSame(Signing::HEADER_TIMESTAMP, Signer::HEADER_TIMESTAMP);
        self::assertSame(Signing::HEADER_IDEMPOTENCY_KEY, Signer::HEADER_IDEMPOTENCY_KEY);
        self::assertSame(Signing::SKEW_SECONDS, Signer::SKEW_SECONDS);
        self::assertSame(Signing::MAX_IDEMPOTENCY_KEY_LENGTH, Idempotency::MAX_KEY_LENGTH);
        self::assertSame(Signing::SKEW_SECONDS, Verifier::DEFAULT_TOLERANCE_SECONDS);
        self::assertSame(Signing::HEADER_WEBHOOK_TIMESTAMP, Verifier::HEADER_TIMESTAMP);
        self::assertSame(Signing::HEADER_WEBHOOK_SIGNATURE, Verifier::HEADER_SIGNATURE);
        self::assertSame(Signing::HEADER_WEBHOOK_SIGNATURE_PREV, Verifier::HEADER_SIGNATURE_PREV);
        self::assertSame(Signing::HEADER_WEBHOOK_EVENT, Verifier::HEADER_EVENT);
        self::assertSame(Signing::HEADER_WEBHOOK_ID, Verifier::HEADER_ID);
        self::assertSame(Signing::HEADER_WEBHOOK_EVENT_ID, Verifier::HEADER_EVENT_ID);
        self::assertSame(Signing::HEADER_WEBHOOK_EVENT_TIME, Verifier::HEADER_EVENT_TIME);
        self::assertSame(Signing::HEADER_WEBHOOK_TEST, Verifier::HEADER_TEST);
    }

    /** The canonical strings follow the generated order and separators. */
    public function testCanonicalStringsFollowTheGeneratedOrder(): void
    {
        $parts = ['ts' => '7', 'METHOD' => 'POST', 'request_uri' => '/v1/x?a=1', 'idempotency_key' => 'k', 'body' => '{}'];
        $want = implode(
            Signing::REQUEST_CANONICAL_SEPARATOR,
            array_map(static fn (string $p): string => $parts[$p], Signing::REQUEST_CANONICAL_ORDER)
        );
        self::assertSame($want, Signer::canonical(7, 'post', '/v1/x?a=1', 'k', '{}'));

        $hook = ['ts' => '7', 'payload' => '{"a":1}'];
        $wantHook = implode(
            Signing::WEBHOOK_CANONICAL_SEPARATOR,
            array_map(static fn (string $p): string => $hook[$p], Signing::WEBHOOK_CANONICAL_ORDER)
        );
        self::assertSame(hash_hmac('sha256', $wantHook, 's'), Signer::signWebhook('s', 7, '{"a":1}'));
    }

    /**
     * No hand-written file under src/ or examples/ carries a header name of the protocol (in code or in its
     * docs) or a literal of its limits: everything reads {@see Signing}. A rename in the contract
     * that only the generated file follows would otherwise leave a stale name in an error message
     * or a doc.
     */
    public function testNoHandWrittenCopyOfTheProtocol(): void
    {
        $values = [];
        foreach ((new ReflectionClass(Signing::class))->getConstants() as $name => $value) {
            if (is_string($value) && str_starts_with($name, 'HEADER_')) {
                $values[] = strtolower($value);
            }
        }
        self::assertNotSame([], $values);
        // The skew is not scanned for: its value is also an HTTP status class (`< 300`); the alias
        // assertions above hold it.
        $limits = [Signing::MAX_BODY, Signing::MAX_IDEMPOTENCY_KEY_LENGTH];

        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'examples'] as $dir) {
            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = substr($file->getPathname(), strlen($root) + 1);
                if (!str_ends_with($path, '.php') || str_starts_with($path, 'src/Generated/')) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                $lower = strtolower($text);
                foreach ($values as $header) {
                    // A header name as a whole token, not a prefix of a longer one
                    // (`X-Webhook-Signature` inside `X-Webhook-Signature-Prev`).
                    if (preg_match('/(?<![a-z0-9-])' . preg_quote($header, '/') . '(?![a-z0-9-])/', $lower) === 1) {
                        $offenders[] = $path . ': ' . $header;
                    }
                }
                foreach ($limits as $limit) {
                    if (preg_match('/(?<![\w.])' . $limit . '(?![\w.])/', $text) === 1) {
                        $offenders[] = $path . ': ' . $limit;
                    }
                }
            }
        }
        self::assertSame([], $offenders, 'take these from Oblodai\Generated\Signing');
    }
}
