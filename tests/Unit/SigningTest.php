<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Signer;
use Oblodai\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ports test/unit/signing.test.ts: every signing vector exported from the core's own test suite
 * must reproduce byte-for-byte under Signer::canonical()/Signer::sign()/Signer::signWebhook().
 */
final class SigningTest extends TestCase
{
    /**
     * `Fixtures::contract()` is `array<string, mixed>`, so a fixture list under it is `mixed` —
     * walk it once here, with real per-row narrowing, instead of trusting that shape everywhere.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(string $key): array
    {
        $raw = Fixtures::contract()[$key] ?? null;
        if (!is_array($raw)) {
            self::fail(sprintf('contract.json: "%s" is missing or not a list', $key));
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $typed = [];
            foreach ($row as $field => $value) {
                $typed[(string) $field] = $value;
            }
            $out[] = $typed;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            self::fail(sprintf('fixture field "%s" is not a string', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            self::fail(sprintf('fixture field "%s" is not an int', $key));
        }

        return $value;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function signingVectors(): iterable
    {
        foreach (self::rows('signing_vectors') as $vector) {
            yield self::str($vector, 'name') => [$vector];
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('signingVectors')]
    public function testSigningVector(array $vector): void
    {
        $rawKey = self::str($vector, 'idempotency_key');
        $idempotencyKey = $rawKey !== '' ? $rawKey : null;
        $ts = self::int($vector, 'ts');
        $method = self::str($vector, 'method');
        $requestUri = self::str($vector, 'request_uri');
        $body = self::str($vector, 'body');

        self::assertSame(
            self::str($vector, 'canonical'),
            Signer::canonical($ts, $method, $requestUri, $idempotencyKey, $body)
        );
        self::assertSame(
            self::str($vector, 'signature'),
            Signer::sign(self::str($vector, 'secret'), $ts, $method, $requestUri, $idempotencyKey, $body)
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function webhookVectors(): iterable
    {
        foreach (self::rows('webhook_vectors') as $i => $vector) {
            yield 'vector ' . $i => [$vector];
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('webhookVectors')]
    public function testWebhookVector(array $vector): void
    {
        self::assertSame(
            self::str($vector, 'signature'),
            Signer::signWebhook(self::str($vector, 'secret'), self::int($vector, 'ts'), self::str($vector, 'payload'))
        );
    }

    public function testIdempotencySlotIsEmptyNotAbsentWhenNoKeyIsSent(): void
    {
        $withEmptySlot = Signer::sign('s', 1, 'POST', '/v1/x', '', '{}');
        $legacy = Signer::sign('s', 1, 'POST', '/v1/x', null, '{}');

        self::assertSame($legacy, $withEmptySlot);
        self::assertSame("1\nPOST\n/v1/x\n\n{}", Signer::canonical(1, 'POST', '/v1/x', null, '{}'));
    }

    public function testSignsTheBodyBytesSoAUtf8BodySignsItsRawBytes(): void
    {
        $body = '{"additional_data":"тест"}';
        // The body is a UTF-8 encoded PHP string, i.e. already the exact bytes on the wire — the
        // canonical string (and therefore the signature) is taken over those bytes, not over any
        // character-count view of them.
        self::assertNotSame(strlen($body), mb_strlen($body, 'UTF-8'));

        $expected = hash_hmac('sha256', "5\nPOST\n/v1/payment\n\n" . $body, 's');
        self::assertSame($expected, Signer::sign('s', 5, 'POST', '/v1/payment', null, $body));
    }
}
