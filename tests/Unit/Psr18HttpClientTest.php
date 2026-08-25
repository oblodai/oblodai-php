<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Oblodai\Exception\TransportException;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\Psr18HttpClient;
use Oblodai\Oblodai;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The PSR-18 adapter: any client with PSR-17 factories can carry the SDK's requests, and its
 * failures arrive as `TransportException` like the built-in cURL stack's do.
 */
final class Psr18HttpClientTest extends TestCase
{
    public function testCarriesMethodUrlHeadersAndBodyAndReadsTheResponseBack(): void
    {
        $factory = new Psr17Factory();
        $psr = new RecordingPsrClient($factory, 200, '{"state":0,"result":{"ok":true}}');
        $client = new Psr18HttpClient($psr, $factory, $factory);

        $response = $client->send(
            new HttpRequest('POST', 'https://api.test/v1/payment', ['X-Public-Id' => 'pk'], '{"amount":"1"}'),
            5.0
        );

        self::assertNotNull($psr->last);
        self::assertSame('POST', $psr->last->getMethod());
        self::assertSame('https://api.test/v1/payment', (string) $psr->last->getUri());
        self::assertSame('pk', $psr->last->getHeaderLine('X-Public-Id'));
        self::assertSame('{"amount":"1"}', (string) $psr->last->getBody());
        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('{"state":0,"result":{"ok":true}}', $response->body);
    }

    public function testDrivesTheWholeClientIncludingSigning(): void
    {
        $factory = new Psr17Factory();
        $psr = new RecordingPsrClient($factory, 200, (string) json_encode([
            'state' => 0,
            'result' => ['enabled' => true],
        ]));

        $oblodai = new Oblodai(
            publicId: 'pk',
            secret: 's',
            baseUrl: 'https://api.test',
            http: new Psr18HttpClient($psr, $factory, $factory),
            env: [],
        );
        self::assertTrue($oblodai->splits->getOptIn()->enabled);
        self::assertNotNull($psr->last);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $psr->last->getHeaderLine('X-Signature'));
    }

    public function testANetworkFailureBecomesATransportException(): void
    {
        $factory = new Psr17Factory();
        $client = new Psr18HttpClient(new FailingPsrClient(true), $factory, $factory);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('network error');
        $client->send(new HttpRequest('GET', 'https://api.test/v1/currencies'), 5.0);
    }

    public function testAnyOtherClientFailureAlsoBecomesATransportException(): void
    {
        $factory = new Psr17Factory();
        $client = new Psr18HttpClient(new FailingPsrClient(false), $factory, $factory);

        try {
            $client->send(new HttpRequest('GET', 'https://api.test/v1/currencies'), 5.0);
            self::fail('the adapter must translate every PSR-18 failure');
        } catch (TransportException $err) {
            self::assertSame(TransportException::NETWORK, $err->errorCode);
            self::assertFalse($err->synthetic);
        }
    }
}

/** A PSR-18 client that records what it was handed and answers from a script. */
final class RecordingPsrClient implements ClientInterface
{
    public ?RequestInterface $last = null;

    public function __construct(
        private readonly Psr17Factory $factory,
        private readonly int $status,
        private readonly string $body,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->last = $request;

        return $this->factory->createResponse($this->status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($this->body));
    }
}

/** A PSR-18 client that only fails, either at the network level or above it. */
final class FailingPsrClient implements ClientInterface
{
    public function __construct(private readonly bool $network)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw $this->network
            ? new PsrNetworkException($request)
            : new PsrClientException('the client is misconfigured');
    }
}

final class PsrNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request)
    {
        parent::__construct('connection refused');
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

final class PsrClientException extends RuntimeException implements ClientExceptionInterface
{
}
