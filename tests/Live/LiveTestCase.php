<?php

declare(strict_types=1);

namespace Oblodai\Tests\Live;

use Oblodai\Exception\OblodaiException;
use Oblodai\Oblodai;
use PHPUnit\Framework\TestCase;

/**
 * Base for the live tier: the SDK against a REAL core (`OBLODAI_LIVE_URL`, e.g. a local stack).
 * Onboarding is open on such a stack: `POST /v1/merchants` then `POST /v1/merchants/{id}/sandbox`
 * mints a `test_` key, and everything below runs on fake money.
 */
abstract class LiveTestCase extends TestCase
{
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
        $anonymous = new Oblodai(baseUrl: self::$baseUrl, allowInsecureBaseUrl: true);
        $merchant = $anonymous->merchants->create([
            'email' => sprintf('%s-%d@example.com', $label, (int) (microtime(true) * 1000)),
            'name' => 'SDK live',
        ]);
        $sandbox = $anonymous->merchants->createSandbox($merchant->merchant_id);

        return new Oblodai(
            publicId: $sandbox->api_key->public_id,
            secret: $sandbox->api_key->secret,
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
        } catch (OblodaiException $err) {
            if ($err->errorCode === 'sdk.bad_envelope' || $err->httpStatus === 400) {
                throw $err; // our own request/response shape is wrong — that is a real failure
            }

            return null;
        }
    }

    protected static function uniqueId(string $prefix): string
    {
        return sprintf('%s-%d', $prefix, (int) (microtime(true) * 1000000));
    }
}
