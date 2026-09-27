<?php

declare(strict_types=1);

namespace Oblodai\Tests\Conformance;

use BackedEnum;
use Oblodai\Core\Clock;
use Oblodai\Core\Credentials;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\Signer;
use Oblodai\Core\Transport;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Generated\Facts;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\Backend;
use Oblodai\Tests\Support\Operations;
use Oblodai\Tests\Support\ProbeResource;
use Oblodai\Webhook\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The shared conformance suite every Oblodai SDK runs (backend `tools/sdkgen/conformance`).
 *
 * Scenarios are read from `$SDKGEN_CONFORMANCE`, else from `tools/sdkgen/conformance` of the backend
 * checkout (`$OBLODAI_BACKEND`, else `../oblodai-backend`). Signing vectors are not in the scenario
 * files: each suite names the backend `openapi.json` and a pointer into its `x-oblodai-signing`.
 * Every call scenario runs through the generated method of its operation over a scripted HTTP
 * stack; retry pauses are recorded instead of slept.
 */
final class ConformanceTest extends TestCase
{
    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('conformance field "%s" is not a string', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('conformance field "%s" is not an integer', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function suite(string $name): array
    {
        $dir = Backend::conformance();
        if (!is_dir($dir)) {
            if (Backend::required()) {
                throw new RuntimeException(sprintf('conformance suite not found at %s', $dir));
            }

            return [];
        }

        return Backend::json($dir . '/' . $name . '.json');
    }

    /**
     * A JSON pointer into a decoded document.
     *
     * @param array<mixed> $doc
     */
    private static function resolve(array $doc, string $pointer): mixed
    {
        $cur = $doc;
        foreach (explode('/', ltrim($pointer, '/')) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                throw new RuntimeException(sprintf('pointer %s not found in the spec', $pointer));
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /**
     * The header names of a suite, by role (`header_names`: a pointer to the spec's list and the
     * role of each position). The suite files name no header: the spec does.
     *
     * @return array<string, string>
     */
    private static function headerNames(string $name): array
    {
        $suite = self::suite($name);
        $at = $suite['header_names'] ?? null;
        $src = $suite['source'] ?? null;
        if (!is_array($at) || !is_string($at['pointer'] ?? null) || !is_array($at['roles'] ?? null)
            || !is_array($src) || !is_string($src['spec'] ?? null)) {
            throw new RuntimeException(sprintf('%s: no header_names', $name));
        }
        $names = self::resolve(Backend::suiteSpec($src['spec']), $at['pointer']);
        if (!is_array($names) || count($names) !== count($at['roles'])) {
            throw new RuntimeException(sprintf('%s: %s does not match the roles', $name, $at['pointer']));
        }
        $out = [];
        foreach (array_values($at['roles']) as $i => $role) {
            $header = $names[$i] ?? null;
            if (!is_string($role) || !is_string($header)) {
                throw new RuntimeException(sprintf('%s: role or header #%d is not a string', $name, $i));
            }
            $out[$role] = $header;
        }

        return $out;
    }

    /**
     * The rehearsal header name the spec gives (`header_names.test_pointer`) — again the spec's
     * name, not the SDK's constant.
     */
    private static function testHeader(string $name): string
    {
        $suite = self::suite($name);
        $at = $suite['header_names'] ?? null;
        $pointer = is_array($at) ? ($at['test_pointer'] ?? null) : null;
        $src = $suite['source'] ?? null;
        if (!is_string($pointer) || !is_array($src) || !is_string($src['spec'] ?? null)) {
            throw new RuntimeException(sprintf('%s: no header_names.test_pointer', $name));
        }
        $header = self::resolve(Backend::suiteSpec($src['spec']), $pointer);
        if (!is_string($header) || $header === '') {
            throw new RuntimeException(sprintf('%s: no rehearsal header name at %s', $name, $pointer));
        }

        return $header;
    }

    /**
     * The value of a header, compared by name case-insensitively; null when absent.
     *
     * @param array<string, string> $headers
     */
    private static function headerOf(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The spec's `x-oblodai-signing` and the vectors a suite points at.
     *
     * @param  array<string, mixed> $suite
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private static function source(array $suite): array
    {
        $src = $suite['source'];
        if (!is_array($src) || !is_string($src['spec'] ?? null) || !is_string($src['pointer'] ?? null)) {
            throw new RuntimeException('conformance suite without a source');
        }
        $spec = Backend::suiteSpec($src['spec']);
        $cur = self::resolve($spec, $src['pointer']);
        /** @var array<string, mixed> $signing */
        $signing = $spec['x-oblodai-signing'];
        /** @var list<array<string, mixed>> $cur */

        return [$signing, $cur];
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>}> */
    private static function cases(string $name): iterable
    {
        $suite = self::suite($name);
        if ($suite === []) {
            return;
        }
        [$signing, $vectors] = self::source($suite);
        /** @var list<array<string, mixed>> $checks */
        $checks = $suite['checks'];
        foreach ($checks as $check) {
            foreach ($vectors as $i => $vector) {
                yield sprintf('%s#%d', self::str($check, 'name'), $i) => [$check, $vector, $signing];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>}> */
    public static function signingCases(): iterable
    {
        return self::cases('signing');
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>}> */
    public static function webhookCases(): iterable
    {
        return self::cases('webhook');
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>}> */
    public static function webhookDeliveryCases(): iterable
    {
        foreach (self::cases('webhook_delivery') as $name => [$check, $delivery, $signing]) {
            yield sprintf('%s - %s (%s)', $name, self::str($delivery, 'event'), self::str($check, 'key')) => [$check, $delivery, $signing];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function callScenarios(): iterable
    {
        foreach (['retry', 'money', 'forward_compat'] as $name) {
            $suite = self::suite($name);
            /** @var list<array<string, mixed>> $scenarios */
            $scenarios = $suite['scenarios'] ?? [];
            foreach ($scenarios as $scenario) {
                yield $name . '/' . self::str($scenario, 'name') => [$scenario];
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function webhookBodies(): iterable
    {
        /** @var list<array<string, mixed>> $bodies */
        $bodies = self::suite('forward_compat')['webhooks'] ?? [];
        foreach ($bodies as $case) {
            yield self::str($case, 'name') => [$case];
        }
    }

    public function testTheSuiteIsThere(): void
    {
        if (!is_dir(Backend::conformance())) {
            self::markTestSkipped(sprintf('conformance suite not found at %s; set OBLODAI_BACKEND', Backend::conformance()));
        }
        self::assertNotSame([], iterator_to_array(self::callScenarios()));
        self::assertNotSame([], iterator_to_array(self::signingCases()));
        self::assertNotSame([], iterator_to_array(self::webhookCases()));
        self::assertNotSame([], iterator_to_array(self::webhookBodies()));
    }

    /**
     * forward_compat webhooks: the body parses, keeps its raw `type`, and is known exactly as said.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('webhookBodies')]
    public function testWebhookParse(array $case): void
    {
        /** @var array{known: bool, type: string} $expect */
        $expect = $case['expect'];
        $event = Verifier::parse((string) json_encode($case['body']));
        self::assertSame($expect['type'], $event['type']);
        self::assertSame($expect['known'], Verifier::isKnownEvent($event));
    }

    /**
     * @param array<string, mixed> $check
     * @param array<string, mixed> $vector
     * @param array<string, mixed> $signing
     */
    #[DataProvider('signingCases')]
    public function testRequestSigning(array $check, array $vector, array $signing): void
    {
        $key = self::str($vector, 'idempotency_key') !== '' ? self::str($vector, 'idempotency_key') : null;
        $ts = self::int($vector, 'ts');
        $method = self::str($vector, 'method');
        $uri = self::str($vector, 'request_uri');
        $body = self::str($vector, 'body');
        if ($check['kind'] === 'request_canonical') {
            self::assertSame($vector['canonical'], Signer::canonical($ts, $method, $uri, $key, $body));

            return;
        }
        if ($check['kind'] === 'request_headers') {
            $this->requestHeaders(self::str($check, 'public_id'), $vector);

            return;
        }
        self::assertSame('request_signature', $check['kind']);
        self::assertSame($vector['signature'], Signer::sign(self::str($vector, 'secret'), $ts, $method, $uri, $key, $body));
    }

    /**
     * The vector's request, sent through the signing transport client methods use (the vector's
     * key pair, a clock stopped at its `ts`), carries the spec's headers with the vector's values;
     * without an idempotency key, no key header at all.
     *
     * @param array<string, mixed> $vector
     */
    private function requestHeaders(string $publicId, array $vector): void
    {
        $key = self::str($vector, 'idempotency_key');
        $ts = self::int($vector, 'ts');
        $body = self::str($vector, 'body');
        $uri = self::str($vector, 'request_uri');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $http = new ScriptedHttpClient([['status' => 200, 'json' => ['state' => 0, 'result' => []]]]);
        $transport = new Transport(
            baseUrl: 'https://api.test',
            http: $http,
            userAgent: Oblodai::userAgent(),
            credentials: new Credentials($publicId, self::str($vector, 'secret')),
            clock: new Clock(static fn (): int => $ts),
        );
        $decoded = $body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded === null || is_array($decoded));
        /** @var array<string, mixed>|null $decoded */
        /** @var array<string, mixed> $query */
        (new ProbeResource($transport))->call(
            ProbeResource::route(self::str($vector, 'method'), $path, idempotent: $key !== ''),
            $decoded,
            new RequestOptions(idempotencyKey: $key !== '' ? $key : null),
            query: $query,
        );

        self::assertCount(1, $http->requests);
        $sent = $http->requests[0];
        self::assertSame('https://api.test' . $uri, $sent->url, 'the vector\'s request_uri');
        self::assertSame($body, $sent->body ?? '', 'the vector\'s body bytes');
        $names = self::headerNames('signing');
        self::assertSame($publicId, self::headerOf($sent->headers, $names['public_id']));
        self::assertSame(self::str($vector, 'signature'), self::headerOf($sent->headers, $names['signature']));
        self::assertSame((string) $ts, self::headerOf($sent->headers, $names['timestamp']));
        self::assertSame($key !== '' ? $key : null, self::headerOf($sent->headers, $names['idempotency_key']));
    }

    /**
     * @param array<string, mixed> $check
     * @param array<string, mixed> $vector
     * @param array<string, mixed> $signing
     */
    #[DataProvider('webhookCases')]
    public function testWebhook(array $check, array $vector, array $signing): void
    {
        $secret = self::str($vector, 'secret');
        $ts = self::int($vector, 'ts');
        $payload = self::str($vector, 'payload');
        if ($check['kind'] === 'webhook_signature') {
            self::assertSame($vector['signature'], Signer::signWebhook($secret, $ts, $payload));

            return;
        }
        self::assertSame('webhook_verify', $check['kind']);
        $skew = self::int($signing, 'skew_seconds');
        $offset = match ($check['now_from_ts']) {
            'skew' => $skew,
            'skew+1' => $skew + 1,
            default => self::int($check, 'now_from_ts'),
        };
        $signature = self::str($vector, 'signature');
        if ($check['mutate'] === 'payload') {
            $payload .= ' ';
        } elseif ($check['mutate'] === 'signature') {
            $signature = ($signature[0] !== '0' ? '0' : '1') . substr($signature, 1);
        }
        $names = self::headerNames('webhook');
        $headers = [$names['timestamp'] => (string) $ts, $names['signature'] => $signature];
        if ($check['expect'] === 'ok') {
            try {
                Verifier::verify($payload, $headers, $secret, toleranceSec: $skew, now: $ts + $offset);
            } catch (WebhookPayloadException) {
                // The vectors sign bare payloads, not whole events: a refusal to read the body
                // after the MAC and the window passed still is a pass.
            }
            $this->addToAssertionCount(1);

            return;
        }

        try {
            Verifier::verify($payload, $headers, $secret, toleranceSec: $skew, now: $ts + $offset);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertSame('webhook.' . self::str($check, 'expect'), $e->errorCode);
        }
    }

    public function testEveryEventOfThisReleaseHasADelivery(): void
    {
        $suite = self::suite('webhook_delivery');
        if ($suite === []) {
            self::markTestSkipped(sprintf('conformance suite not found at %s; set OBLODAI_BACKEND', Backend::conformance()));
        }
        [, $deliveries] = self::source($suite);
        $events = array_map(static fn (array $d): string => self::str($d, 'event'), $deliveries);
        $known = array_keys(Facts::WEBHOOK_EVENTS);
        sort($events);
        sort($known);
        self::assertSame($known, $events);
    }

    /**
     * A real delivery of every event of the contract verifies (with the current secret and, as a
     * receiver that has not swapped yet, the previous one), parses into its kind's model and
     * exposes every delivery header of the spec.
     *
     * @param array<string, mixed> $check
     * @param array<string, mixed> $delivery
     * @param array<string, mixed> $signing
     */
    #[DataProvider('webhookDeliveryCases')]
    public function testWebhookDelivery(array $check, array $delivery, array $signing): void
    {
        self::assertSame('webhook_delivery', $check['kind']);
        $secret = match ($check['key']) {
            'current' => self::str($delivery, 'secret'),
            'previous' => self::str($delivery, 'previous_secret'),
            default => throw new RuntimeException('unknown key'),
        };
        /** @var array<string, string> $headers */
        $headers = $delivery['headers'];
        $rehearsal = ($check['test'] ?? false) === true;
        $sent = $headers;
        if ($rehearsal) {
            $sent[self::testHeader('webhook_delivery')] = 'true';
        }
        $got = Verifier::verify(self::str($delivery, 'payload'), $sent, $secret, now: self::int($delivery, 'ts'));
        // The rehearsal header is not signed: it is reported under `unverified` only, and `isTest`
        // follows the signed body's `test` (none of the spec's delivery bodies carry it).
        self::assertSame($rehearsal, $got->unverified->test, 'unverified->test (the rehearsal header of the spec)');
        $payload = json_decode(self::str($delivery, 'payload'), true);
        self::assertSame(is_array($payload) && ($payload['test'] ?? null) === true, $got->isTest, 'isTest comes from the signed body only');
        $kind = self::str($delivery, 'kind');
        self::assertTrue(Verifier::isKnownEvent($got->event), $kind);
        self::assertSame($kind, $got->event['type']);
        self::assertInstanceOf(Facts::WEBHOOK_MODELS[$kind], Verifier::model($got->event));
        $names = self::headerNames('webhook_delivery');
        /** @var array<string, string> $fields */
        $fields = self::suite('webhook_delivery')['fields'];
        self::assertSame(array_keys($names), array_keys($fields), 'a delivery field for every header role');
        foreach ($fields as $role => $field) {
            $header = $names[$role];
            $value = match ($field) {
                '' => $headers[$header],
                // Every header but the timestamp is unsigned and lives under `unverified`.
                'id' => $got->unverified->deliveryId,
                'event_id' => $got->unverified->eventId,
                'event_type' => $got->unverified->eventType,
                'event_time' => $got->unverified->eventTime === null ? null : (string) $got->unverified->eventTime,
                'sent_at' => (string) $got->sentAt,
                default => throw new RuntimeException(sprintf('the delivery has no field %s for %s', $field, $header)),
            };
            self::assertSame($headers[$header], $value, sprintf('%s != %s', $field, $header));
        }
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('callScenarios')]
    public function testCall(array $scenario): void
    {
        /** @var array{operation: string, args: array<string, mixed>} $call */
        $call = $scenario['call'];
        /** @var list<array<string, mixed>> $responses */
        $responses = $scenario['responses'];
        $http = new ScriptedHttpClient($responses);
        $delaysMs = [];
        $transport = new Transport(
            baseUrl: 'https://api.test',
            http: $http,
            userAgent: Oblodai::userAgent(),
            credentials: new Credentials('pk_conformance', 'conformance-secret'),
            sleep: static function (float $seconds) use (&$delaysMs): void {
                $delaysMs[] = $seconds * 1000.0;
            },
        );
        $op = Operations::get($call['operation']);
        $class = 'Oblodai\\Generated\\Resource\\' . $op['class'];
        $resource = new $class($transport);
        $method = [$resource, $op['method']];
        self::assertIsCallable($method);

        $result = null;
        $error = null;

        try {
            $result = $call['args'] !== [] ? $method($call['args']) : $method();
        } catch (OblodaiException $e) {
            $error = $e;
        }

        /** @var array<string, mixed> $expect */
        $expect = $scenario['expect'];
        self::assertCount(self::int($expect, 'requests'), $http->requests, 'requests sent');
        $keys = $http->keys();
        if (($expect['idempotency_key'] ?? null) === 'absent') {
            self::assertSame(array_fill(0, count($keys), null), $keys);
        } elseif (($expect['idempotency_key'] ?? null) === 'present') {
            self::assertNotContains(null, $keys);
        }
        if (($expect['same_idempotency_key'] ?? false) === true) {
            self::assertNotNull($keys[0] ?? null);
            self::assertCount(1, array_unique(array_map('strval', $keys)), 'one key for every attempt');
        }
        if (array_key_exists('delays_ms', $expect)) {
            self::assertEquals($expect['delays_ms'], $delaysMs);
        }
        /** @var array<string, mixed> $bodyFields */
        $bodyFields = $expect['request_body_field'] ?? [];
        foreach ($bodyFields as $name => $want) {
            $last = end($http->requests);
            self::assertNotFalse($last);
            $sent = json_decode((string) $last->body, true);
            self::assertIsArray($sent);
            self::assertSame($want, $sent[$name] ?? null);
        }
        if (array_key_exists('error_code', $expect)) {
            self::assertInstanceOf(OblodaiException::class, $error);
            self::assertSame($expect['error_code'], $error->errorCode);

            return;
        }
        if ($error !== null) {
            throw $error;
        }
        /** @var array<string, mixed> $fields */
        $fields = $expect['result_field'] ?? [];
        foreach ($fields as $name => $want) {
            self::assertIsObject($result);
            $value = get_object_vars($result)[$name] ?? null;
            self::assertSame($want, $value instanceof BackedEnum ? $value->value : $value, $name);
        }
    }
}
