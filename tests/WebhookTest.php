<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Exception\SignatureException;
use Oblodai\Signing;
use Oblodai\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    public function testAcceptsValid(): void
    {
        $secret = 'whsec';
        $body = '{"type":"payment","uuid":"inv_1","status":"paid"}';
        $ts = (string) time();
        $sig = Signing::computeWebhookSignature($secret, $ts, $body);

        self::assertTrue(Webhooks::verify($secret, $body, $ts, $sig));
    }

    public function testRejectsTamperedBody(): void
    {
        $secret = 'whsec';
        $body = '{"a":1}';
        $ts = (string) time();
        $sig = Signing::computeWebhookSignature($secret, $ts, $body);

        $this->expectException(SignatureException::class);
        Webhooks::verify($secret, $body . ' ', $ts, $sig);
    }

    public function testRejectsStale(): void
    {
        $secret = 'whsec';
        $body = '{"a":1}';
        $ts = (string) (time() - 400);
        $sig = Signing::computeWebhookSignature($secret, $ts, $body);

        $this->expectException(SignatureException::class);
        Webhooks::verify($secret, $body, $ts, $sig, 300);
    }

    public function testIsTestDetection(): void
    {
        self::assertTrue(Webhooks::isTest('{"is_test":true}'));
        self::assertFalse(Webhooks::isTest('{"type":"payment"}'));
    }
}
