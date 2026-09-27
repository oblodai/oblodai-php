<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Core\Signer;
use Oblodai\Generated\Model\ConversionWebhook;
use Oblodai\Generated\Model\PaymentWebhook;
use Oblodai\Generated\Signing;
use Oblodai\Tests\Support\MockGateway;
use Oblodai\Tests\Support\Samples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The examples run (spec §3 item 10): each `examples/*.php` executes against the mock gateway,
 * and the webhook receiver is served and sent signed deliveries. The scripts are what a merchant
 * copies first, so a renamed method or a changed result shape must break a test, not the merchant.
 */
final class ExamplesTest extends TestCase
{
    /** @var resource|null */
    private static $gateway = null;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        [self::$gateway, self::$baseUrl] = MockGateway::start('tests/Support/mock-gateway.php');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$gateway !== null) {
            MockGateway::stop(self::$gateway);
            self::$gateway = null;
        }
    }

    public function testEveryExampleIsCovered(): void
    {
        $scripts = array_map('basename', glob(dirname(__DIR__, 2) . '/examples/*.php') ?: []);
        sort($scripts);

        self::assertSame(
            ['_bootstrap.php', 'accept-payment.php', 'sandbox-journey.php', 'send-payout.php', 'webhook-receiver.php'],
            $scripts
        );
    }

    /** @return iterable<string, array{string, string}> example => the line its main path ends on */
    public static function scripts(): iterable
    {
        yield 'accept-payment' => ['accept-payment.php', 'paid - release the goods'];
        yield 'send-payout' => ['send-payout.php', 'txid '];
        yield 'sandbox-journey' => ['sandbox-journey.php', 'reset: 0 invoices cancelled'];
    }

    #[DataProvider('scripts')]
    public function testTheExampleRunsToTheEnd(string $script, string $reaches): void
    {
        [$code, $out, $err] = MockGateway::run(['examples/' . $script], [
            'OBLODAI_PUBLIC_ID' => 'test_oblodai_example',
            'OBLODAI_SECRET' => str_repeat('s', 32),
            'OBLODAI_BASE_URL' => self::$baseUrl,
            'OBLODAI_ALLOW_INSECURE' => '1',
        ]);

        self::assertSame(0, $code, $script . " failed:\n" . $out . $err);
        self::assertStringContainsString($reaches, $out);
    }

    public function testAnExampleWithoutKeysStopsWithOneLine(): void
    {
        [$code, $out, $err] = MockGateway::run(['examples/accept-payment.php'], ['OBLODAI_BASE_URL' => self::$baseUrl, 'OBLODAI_ALLOW_INSECURE' => '1']);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringStartsWith('set OBLODAI_PUBLIC_ID, OBLODAI_SECRET', $err);
    }

    public function testTheWebhookReceiverAnswersEachDeliveryTheRightWay(): void
    {
        $log = self::freshLog();
        [$receiver, $url] = MockGateway::start('examples/webhook-receiver.php', ['OBLODAI_WEBHOOK_SECRET' => 'whsec-example', 'OBLODAI_WEBHOOK_LOG' => $log]);

        try {
            $body = (string) json_encode(Samples::of(PaymentWebhook::class, ['type' => 'payment', 'status' => 'paid', 'order_id' => 'o-7', 'uuid' => 'u-7']));
            $now = time();
            $signed = [Signing::HEADER_WEBHOOK_TIMESTAMP => (string) $now, Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec-example', $now, $body), Signing::HEADER_WEBHOOK_EVENT_ID => 'e-1'];

            self::assertSame([200, 'ok: order o-7 paid with x x'], self::post($url, $body, $signed));
            self::assertSame([401, 'rejected: [webhook.bad_signature] signature does not match the body'], self::post($url, $body . ' ', $signed));
            $rehearsal = (string) json_encode(['type' => 'payment', 'uuid' => 'u-8', 'test' => true]);
            self::assertSame([200, 'rehearsal - not applied'], self::post($url, $rehearsal, [
                Signing::HEADER_WEBHOOK_TIMESTAMP => (string) $now, Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec-example', $now, $rehearsal),
            ]));
            $alien = (string) json_encode(['type' => 'teleport', 'uuid' => 'u-9']);
            self::assertSame([200, 'ok: unmodelled type teleport'], self::post($url, $alien, [
                Signing::HEADER_WEBHOOK_TIMESTAMP => (string) $now, Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec-example', $now, $alien),
            ]));
            self::assertSame([200, 'duplicate'], self::post($url, $body, $signed));
            // A captured delivery replayed with a fresh (unsigned) event id header is still a duplicate.
            self::assertSame([200, 'duplicate'], self::post($url, $body, [Signing::HEADER_WEBHOOK_EVENT_ID => 'e-forged'] + $signed));
            // A rehearsal naming a real order, marked paid: signed, acknowledged, never fulfilled.
            $paidRehearsal = (string) json_encode(Samples::of(PaymentWebhook::class, ['type' => 'payment', 'status' => 'paid', 'order_id' => 'o-8', 'uuid' => 'u-10', 'sequence' => 0, 'test' => true]));
            self::assertSame([200, 'rehearsal - not applied'], self::post($url, $paidRehearsal, [
                Signing::HEADER_WEBHOOK_TIMESTAMP => (string) $now, Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec-example', $now, $paidRehearsal),
            ]));
            // The unsigned rehearsal header cannot make a live payment look like a test.
            $live = (string) json_encode(Samples::of(PaymentWebhook::class, ['type' => 'payment', 'status' => 'paid', 'order_id' => 'o-11', 'uuid' => 'u-11']));
            self::assertSame([200, 'ok: order o-11 paid with x x'], self::post($url, $live, [
                Signing::HEADER_WEBHOOK_TIMESTAMP => (string) $now, Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec-example', $now, $live), Signing::HEADER_WEBHOOK_TEST => 'true',
            ]));
        } finally {
            MockGateway::stop($receiver);
            @unlink($log);
        }
    }

    /**
     * Ordering is per object, and a conversion's object is its `id`: conversion B arriving after
     * conversion A with a lower sequence is B's first state and is applied; an older state of A
     * arriving late is stale.
     */
    public function testTheWebhookReceiverOrdersEachConversionOnItsOwn(): void
    {
        $log = self::freshLog();
        [$receiver, $url] = MockGateway::start('examples/webhook-receiver.php', ['OBLODAI_WEBHOOK_SECRET' => 'whsec-example', 'OBLODAI_WEBHOOK_LOG' => $log]);

        try {
            $now = time();
            $deliver = static function (string $id, string $status, int $sequence, string $eventId) use ($url, $now): array {
                $body = (string) json_encode(Samples::of(ConversionWebhook::class, ['type' => 'conversion', 'id' => $id, 'status' => $status, 'sequence' => $sequence]));

                return self::post($url, $body, [
                    Signing::HEADER_WEBHOOK_TIMESTAMP => (string) $now, Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec-example', $now, $body), Signing::HEADER_WEBHOOK_EVENT_ID => $eventId,
                ]);
            };
            self::assertSame([200, 'ok: conversion completed'], $deliver('A', 'completed', 5, 'e-a5'));
            self::assertSame([200, 'ok: conversion completed'], $deliver('B', 'completed', 3, 'e-b3'));
            self::assertSame([200, 'stale'], $deliver('A', 'refunded', 4, 'e-a4'));
        } finally {
            MockGateway::stop($receiver);
            @unlink($log);
        }
    }

    /** A path for the example's delivery log that no earlier run has written. */
    private static function freshLog(): string
    {
        $log = tempnam(sys_get_temp_dir(), 'oblodai-webhook-log-');
        self::assertIsString($log);
        unlink($log);

        return $log;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function post(string $url, string $body, array $headers): array
    {
        $lines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $answer = (string) file_get_contents($url . '/', false, $context);
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, trim($answer)];
    }
}
