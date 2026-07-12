<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Signing;
use PHPUnit\Framework\TestCase;

final class SigningTest extends TestCase
{
    public function testSignRequestMatchesReference(): void
    {
        $secret = 'test_secret';
        $body = '{"amount":"25.00"}';
        [$ts, $sig] = Signing::signRequest($secret, 'POST', '/v1/payment', $body, '1700000000');

        $expected = hash_hmac('sha256', "1700000000\nPOST\n/v1/payment\n" . $body, $secret);

        self::assertSame('1700000000', $ts);
        self::assertSame($expected, $sig);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig);
    }

    public function testWebhookSignature(): void
    {
        $expected = hash_hmac('sha256', '1700000000.' . '{"x":1}', 'wh_secret');
        self::assertSame($expected, Signing::computeWebhookSignature('wh_secret', '1700000000', '{"x":1}'));
    }
}
