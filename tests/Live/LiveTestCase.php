<?php

declare(strict_types=1);

namespace Oblodai\Tests\Live;

use Oblodai\Core\RouteSpec;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\ValidationException;
use Oblodai\Oblodai;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base for the live tier: the SDK against a REAL gateway (`OBLODAI_LIVE_URL`, e.g. a local stack).
 * Onboarding is open on such a stack: `POST /v1/merchants` (not part of the merchant contract, so
 * sent through the transport) then `sandbox->onboardStore($merchantId)` mints a `test_oblodai_…`
 * key, and everything below runs on fake money.
 */
abstract class LiveTestCase extends TestCase
{
    protected const ADDRESS = 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx';

    protected static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $url = getenv('OBLODAI_LIVE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('set OBLODAI_LIVE_URL to run the live tier');
        }
        self::$baseUrl = rtrim($url, '/');
    }

    /** A client signed with a freshly provisioned sandbox key. */
    protected static function onboardSandbox(string $label): Oblodai
    {
        $anonymous = self::anonymous();
        $merchant = $anonymous->transport->call(
            new RouteSpec('createMerchant', 'POST', '/v1/merchants', 'onboard', false, false, false),
            ['email' => sprintf('%s-%d@example.com', $label, (int) (microtime(true) * 1000)), 'name' => 'SDK live'],
        );
        $merchantId = is_array($merchant) && is_string($merchant['merchant_id'] ?? null)
            ? $merchant['merchant_id']
            : throw new RuntimeException('onboarding answered without merchant_id');
        $store = $anonymous->sandbox->onboardStore($merchantId);

        return new Oblodai(
            publicId: $store->api_key->public_id,
            secret: $store->api_key->secret,
            baseUrl: self::$baseUrl,
            allowInsecureBaseUrl: true,
        );
    }

    /** A client with no credentials — the payer/recipient side. */
    protected static function anonymous(): Oblodai
    {
        return new Oblodai(baseUrl: self::$baseUrl, allowInsecureBaseUrl: true);
    }

    /**
     * Run a call that may legitimately be refused on this stand (a disabled subsystem, a business
     * rule). SDK-side contract or shape problems still fail the test.
     *
     * @template T
     *
     * @param  callable(): T $call
     * @return T|null
     */
    protected function accept(callable $call): mixed
    {
        try {
            return $call();
        } catch (ContractException|ValidationException $err) {
            throw $err; // our own request/response shape is wrong — that is a real failure
        } catch (OblodaiException) {
            return null;
        }
    }

    protected static function uniqueId(string $prefix): string
    {
        return sprintf('%s-%d', $prefix, (int) (microtime(true) * 1000000));
    }

    protected static function hookUrl(): string
    {
        $hook = getenv('OBLODAI_LIVE_HOOK_URL');

        return is_string($hook) && $hook !== '' ? $hook : 'http://127.0.0.1:8096/hook';
    }
}
