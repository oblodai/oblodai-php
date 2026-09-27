<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Oblodai\Core\FileResult;
use Oblodai\Core\Hooks;
use Oblodai\Core\RequestInfo;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\Retry;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\TransportException;
use Oblodai\Generated\Model\PaymentView;
use Oblodai\Generated\Signing;
use Oblodai\Helper\Money;
use Oblodai\Http\CurlHttpClient;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\Psr18HttpClient;
use Oblodai\Log\Redactor;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use Oblodai\Tests\Support\Samples;
use Oblodai\Webhook\Verifier;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Regression tests for the 2026-09 security review of the SDK (one block per finding). */
final class SecurityFixesTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk', 'secret' => 's', 'baseUrl' => 'https://api.test'];

    private const SECRET = 'oblodai_live_SUPERSECRET_KEY_123';

    private const WEBHOOK_SECRET = 'whsec_REAL_ENDPOINT_SECRET';

    private string $ignoreArgs = '1';

    protected function setUp(): void
    {
        // PHP's built-in default and the official Docker images' setting: trace args are recorded.
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
    }

    /** Every string reachable from a throwable's trace arguments (arrays and objects walked). */
    private static function assertTraceHasNo(Throwable $err, string ...$secrets): void
    {
        $seen = [];
        $walk = static function (mixed $value, int $depth) use (&$walk, &$seen): void {
            if ($depth > 6) {
                return;
            }
            if (is_string($value)) {
                $seen[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $k => $v) {
                    $seen[] = (string) $k;
                    $walk($v, $depth + 1);
                }
            } elseif (is_object($value)) {
                $walk((array) $value, $depth + 1);
            }
        };
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            foreach ($e->getTrace() as $frame) {
                $walk($frame['args'] ?? [], 0);
            }
            $seen[] = $e->getTraceAsString();
            $seen[] = $e->getMessage();
        }
        $text = implode("\n", $seen);
        foreach ($secrets as $secret) {
            self::assertFalse(str_contains($text, $secret), 'a trace argument carries ' . substr($secret, 0, 12) . '…');
        }
    }

    // --- H1: secrets never land in stack-trace arguments -------------------------------------

    public function testTheWebhookSecretsAreNotInTheTraceOfARejectedDelivery(): void
    {
        try {
            Verifier::verify('{"type":"payment"}', [], self::WEBHOOK_SECRET, 'whsec_PREVIOUS_SECRET');
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertTraceHasNo($e, self::WEBHOOK_SECRET, 'whsec_PREVIOUS_SECRET');
        }

        try {
            Verifier::verify('{"type":"payment"}', [
                Signing::HEADER_WEBHOOK_TIMESTAMP => '1',
                Signing::HEADER_WEBHOOK_SIGNATURE => str_repeat('ab', 32),
            ], self::WEBHOOK_SECRET);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertTraceHasNo($e, self::WEBHOOK_SECRET);
        }
    }

    public function testTheApiSecretAndAdminTokenAreNotInTheTraceOfAConstructionError(): void
    {
        try {
            new Oblodai(publicId: 'pub_1', secret: self::SECRET, baseUrl: 'http://api.example.test', adminToken: 'ADMIN_TOKEN_SECRET', env: []);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertTraceHasNo($e, self::SECRET, 'ADMIN_TOKEN_SECRET');
        }
    }

    public function testTheSignatureIsNotInTheTraceOfATransportError(): void
    {
        // A port nothing listens on: cURL fails inside CurlHttpClient with the signed request in hand.
        $ob = new Oblodai(
            publicId: 'pk',
            secret: self::SECRET,
            baseUrl: 'http://127.0.0.1:9',
            http: new CurlHttpClient(),
            retry: new Retry(maxRetries: 0),
            allowInsecureBaseUrl: true,
            env: [],
        );

        try {
            $ob->account->getBalance();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertTraceHasNo($e, self::SECRET, Signing::HEADER_SIGNATURE);
        }
    }

    public function testTheCredentialsAreNotInTheTraceOfABadPathParameter(): void
    {
        $ob = new Oblodai(publicId: 'pk', secret: self::SECRET, baseUrl: 'https://api.test', http: new FakeHttpClient(), env: []);

        try {
            $ob->payoutLinks->getPayoutClaim('a/CLAIMTOKEN_secret');
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame(ConfigException::BAD_PATH_PARAM, $e->errorCode);
            // The message masks the token; the SDK's own frames hide it too (the generated method's
            // own `$token` argument is outside the hand-written core).
            self::assertStringNotContainsString('CLAIMTOKEN_secret', $e->getMessage());
            self::assertTraceHasNo($e, self::SECRET);
        }
    }

    // --- M1 / R11: pagination ----------------------------------------------------------------

    public function testPaginationWalksEveryPageWhenTheServerClampsThePageSize(): void
    {
        // The core answers an out-of-range limit with 25 per page instead of refusing it.
        $pages = [];
        foreach ([0, 25, 50] as $offset) {
            $items = [];
            for ($i = $offset; $i < min($offset + 25, 60); ++$i) {
                $items[] = Samples::of(PaymentView::class, ['uuid' => 'p-' . $i]);
            }
            $pages[] = FakeHttpClient::ok(['items' => $items, 'paginate' => [
                'total' => 60, 'per_page' => 25, 'offset' => $offset, 'has_pages' => $offset + 25 < 60,
            ]]);
        }
        $fake = new FakeHttpClient($pages);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        self::assertCount(60, $ob->payments->listHistory(['limit' => 200])->all());
        self::assertSame(3, $fake->count());
    }

    // --- M4 / L1 / R9: validation regexes are anchored with \z -------------------------------

    public function testMoneyRefusesATrailingNewline(): void
    {
        foreach (["25\n", "9\n", "1.5\n"] as $amount) {
            try {
                Money::assertAmount($amount);
                self::fail('accepted ' . json_encode($amount));
            } catch (ConfigException $e) {
                self::assertSame(ConfigException::BAD_AMOUNT, $e->errorCode);
            }
        }
        $this->expectException(ConfigException::class);
        Money::compare("9\n", '10');
    }

    public function testHeaderValuesIdsAndKeysRefuseATrailingLineFeed(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['balance' => ['merchant' => []]])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);
        $attempts = [
            'requestId' => static fn () => $ob->account->getBalance(new RequestOptions(requestId: "req-1\n")),
            'header value' => static fn () => $ob->account->getBalance(new RequestOptions(extraHeaders: ['X-Trace' => "t\n"])),
            'header name' => static fn () => $ob->account->getBalance(new RequestOptions(extraHeaders: ["X-Trace\n" => 't'])),
            'idempotency key' => static fn () => $ob->payments->create(['amount' => '1', 'currency' => 'USDT'], new RequestOptions(idempotencyKey: "k-1\n")),
        ];
        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                self::fail($what . ' with a trailing LF was accepted');
            } catch (ConfigException) {
            }
        }
        self::assertSame(0, $fake->count());
    }

    // --- L2 / R3: secrets in URLs never reach hooks or messages ------------------------------

    public function testHooksSeeTheClaimTokenMasked(): void
    {
        $seen = [];
        $fake = new FakeHttpClient([FakeHttpClient::ok(['status' => 'active'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, hooks: new Hooks(onRequest: static function (RequestInfo $info) use (&$seen): void {
            $seen[] = $info;
        }), env: []);

        try {
            $ob->payoutLinks->getPayoutClaim('CLAIMTOKEN_secret123');
        } catch (OblodaiException) {
            // the sample answer may not parse; only the hook matters here
        }
        self::assertSame('https://api.test/v1/claim/[redacted]', $seen[0]->url);
        self::assertStringNotContainsString('CLAIMTOKEN_secret123', (string) json_encode($seen[0]));
        self::assertStringContainsString('/v1/claim/CLAIMTOKEN_secret123', $fake->calls[0]->url, 'the wire still carries it');
    }

    public function testSignedLinkParametersAndCredentialHeadersAreMasked(): void
    {
        self::assertSame(
            'https://api.test/v1/documents/payout/u-1?exp=[redacted]&sig=[redacted]&lang=en',
            Redactor::url('https://api.test/v1/documents/payout/u-1?exp=1787761591&sig=abcdef&lang=en', '/v1/documents/{kind}/{id}')
        );
        self::assertSame('https://api.test/p/v1/aml/[redacted]', Redactor::url('https://u:p@api.test/p/v1/aml/TOK', '/v1/aml/{token}'));
        $headers = Redactor::headers([
            'authorization' => 'Bearer A', 'X-API-KEY' => 'K', 'x-signature' => 'S', 'X-Admin-Token' => 'T',
            'x-claim-passcode' => 'P', 'X-Trace' => 'keep',
        ]);
        self::assertSame(['[redacted]'], array_values(array_unique(array_diff_key($headers, ['X-Trace' => 1]))));
        self::assertSame('keep', $headers['X-Trace']);
    }

    public function testATooLargeResponseErrorNamesTheRouteWithoutTheToken(): void
    {
        $factory = new Psr17Factory();
        $psr = new class ($factory) implements ClientInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)
                    ->withBody($this->factory->createStream(str_repeat('x', HttpRequest::MAX_JSON_BYTES + 10)));
            }
        };
        $ob = new Oblodai(...self::CREDS, http: new Psr18HttpClient($psr, $factory, $factory), retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->payoutLinks->getPayoutClaim('CLAIMTOKEN_secret123');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::RESPONSE_TOO_LARGE, $e->errorCode);
            self::assertStringContainsString('/v1/claim/[redacted]', $e->getMessage());
            self::assertStringNotContainsString('CLAIMTOKEN_secret123', (string) $e);
        }
    }

    // --- L3: cURL options never print ---------------------------------------------------------

    public function testTheCurlClientDumpsNoOptionValues(): void
    {
        $client = new CurlHttpClient([CURLOPT_PROXYUSERPWD => 'proxyuser:PROXY-PASSWORD']);
        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean() . print_r($client, true) . serialize($client);
        self::assertStringNotContainsString('PROXY-PASSWORD', $dump);
    }

    // --- R2: a single response never moves the signing clock far, nor unproven ----------------

    public function testTheDateOffsetIsNotAdoptedWhenTheResignedAttemptFailsForAnotherReason(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $fake = new FakeHttpClient([
            FakeHttpClient::error(401, ['code' => 'merchant.bad_signature', 'retryable' => false], ['date' => $date]),
            FakeHttpClient::error(404, ['code' => 'payment.not_found', 'retryable' => false]),
            FakeHttpClient::ok(['balance' => ['merchant' => []]]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->account->getBalance();
            self::fail('expected the 404');
        } catch (OblodaiException $e) {
            self::assertSame('payment.not_found', $e->errorCode);
        }
        self::assertGreaterThan(500, abs((int) $fake->header(1, Signing::HEADER_TIMESTAMP) - time()), 'the re-sign used server time');
        $ob->account->getBalance();
        self::assertLessThan(5, abs((int) $fake->header(2, Signing::HEADER_TIMESTAMP) - time()), 'the client clock never moved');
    }

    public function testTheDateOffsetIsAdoptedAfterTheResignedAttemptSucceeds(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 600) . ' GMT';
        $fake = new FakeHttpClient([
            FakeHttpClient::error(401, ['code' => 'merchant.bad_signature', 'retryable' => false], ['date' => $date]),
            FakeHttpClient::ok(['balance' => ['merchant' => []]]),
            FakeHttpClient::ok(['balance' => ['merchant' => []]]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);
        $ob->account->getBalance();
        $ob->account->getBalance();
        self::assertGreaterThan(500, abs((int) $fake->header(2, Signing::HEADER_TIMESTAMP) - time()));
    }

    public function testADateBeyondNineHundredSecondsIsIgnored(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 5000) . ' GMT';
        $fake = new FakeHttpClient([
            FakeHttpClient::error(401, ['code' => 'merchant.bad_signature', 'retryable' => false], ['date' => $date]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->account->getBalance();
            self::fail('expected the 401');
        } catch (OblodaiException $e) {
            self::assertSame('merchant.bad_signature', $e->errorCode);
        }
        self::assertSame(1, $fake->count(), 'no re-sign with an implausible Date');
    }

    // --- R6: downloads -------------------------------------------------------------------------

    public function testFileNamesAreReducedToASafeBaseName(): void
    {
        self::assertSame('passwd', FileResult::filenameFrom('attachment; filename="../../etc/passwd"'));
        self::assertSame('.bashrc', FileResult::filenameFrom("attachment; filename*=UTF-8''..%2F..%2F.bashrc"));
        self::assertSame('x.pdf', FileResult::filenameFrom('attachment; filename="C:\\Windows\\x.pdf"'));
        self::assertSame('ab.pdf', FileResult::filenameFrom("attachment; filename*=UTF-8''a%0Ab%07.pdf"));
        self::assertNull(FileResult::filenameFrom('attachment; filename=".."'));
        self::assertNull(FileResult::filenameFrom('attachment; filename="."'));
        self::assertNull(FileResult::filenameFrom("attachment; filename*=UTF-8''%2F"));
        self::assertSame('report.pdf', FileResult::filenameFrom('attachment; filename="report.pdf"'));
    }

    public function testSaveToNeverOverwritesByDefaultAndWritesPrivately(): void
    {
        $dir = sys_get_temp_dir() . '/oblodai-save-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/statement.pdf';

        try {
            $file = new FileResult('%PDF-1', 'application/pdf', 'statement.pdf');
            self::assertSame(6, $file->saveTo($path));
            self::assertSame(0o600, fileperms($path) & 0o777);

            file_put_contents($path, 'precious');

            try {
                (new FileResult('new', 'text/plain'))->saveTo($path);
                self::fail('an existing file was overwritten');
            } catch (ConfigException $e) {
                self::assertSame(ConfigException::FILE_EXISTS, $e->errorCode);
            }
            self::assertSame('precious', file_get_contents($path));

            self::assertSame(3, (new FileResult('new', 'text/plain'))->saveTo($path, overwrite: true));
            self::assertSame('new', file_get_contents($path));
            self::assertSame(0o600, fileperms($path) & 0o777);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    // --- R10: body size ----------------------------------------------------------------------

    public function testABodyAboveMaxBodyIsRefusedBeforeSending(): void
    {
        $fake = new FakeHttpClient();
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->payments->create(['amount' => '1', 'currency' => 'USDT', 'order_id' => str_repeat('x', Signing::MAX_BODY)]);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame(ConfigException::BODY_TOO_LARGE, $e->errorCode);
        }
        self::assertSame(0, $fake->count());
    }
}
