<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Signer;
use PHPUnit\Framework\TestCase;

/**
 * Signing details the vectors do not spell out. The vectors themselves (`x-oblodai-signing` of the
 * gateway's OpenAPI contract) run in tests/Conformance/ConformanceTest.php.
 */
final class SigningTest extends TestCase
{
    public function testIdempotencySlotIsEmptyNotAbsentWhenNoKeyIsSent(): void
    {
        $withEmptySlot = Signer::sign('s', 1, 'POST', '/v1/x', '', '{}');
        $legacy = Signer::sign('s', 1, 'POST', '/v1/x', null, '{}');

        self::assertSame($legacy, $withEmptySlot);
        self::assertSame("1\nPOST\n/v1/x\n\n{}", Signer::canonical(1, 'POST', '/v1/x', null, '{}'));
    }

    public function testSignsTheBodyBytesSoAUtf8BodySignsItsRawBytes(): void
    {
        $body = '{"additional_data":"tëst"}';
        // The body is a UTF-8 encoded PHP string, i.e. already the exact bytes on the wire — the
        // canonical string (and therefore the signature) is taken over those bytes, not over any
        // character-count view of them.
        self::assertNotSame(strlen($body), mb_strlen($body, 'UTF-8'));

        $expected = hash_hmac('sha256', "5\nPOST\n/v1/payment\n\n" . $body, 's');
        self::assertSame($expected, Signer::sign('s', 5, 'POST', '/v1/payment', null, $body));
    }
}
