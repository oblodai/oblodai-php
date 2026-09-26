<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Exception\ApiException;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\TransportException;
use Oblodai\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/** An error reads in a log line: `[code] text (request_id=…)` (spec §3 item 6). */
final class ErrorStringTest extends TestCase
{
    public function testTheMessageCarriesCodeTextAndRequestId(): void
    {
        $err = ApiException::from(400, ['code' => 'payment.bad_amount', 'message' => 'bad', 'request_id' => 'rq-9']);

        self::assertInstanceOf(ValidationException::class, $err);
        self::assertSame('[payment.bad_amount] bad (request_id=rq-9)', $err->getMessage());
        self::assertSame('[payment.bad_amount] bad (request_id=rq-9)', (string) $err);
        self::assertSame('bad', $err->detail);
    }

    public function testWithoutARequestIdThereIsNoSuffix(): void
    {
        $err = ApiException::from(400, ['code' => 'payment.bad_amount', 'message' => 'bad']);

        self::assertSame('[payment.bad_amount] bad', $err->getMessage());
    }

    public function testEverySubclassRendersTheSameWay(): void
    {
        self::assertSame('[sdk.float_amount] no floats', (string) new ConfigException('sdk.float_amount', 'no floats', 'amount'));
        self::assertSame('[transport.timeout] slow', (string) new TransportException('transport.timeout', 'slow'));
        self::assertSame('[sdk.bad_envelope] odd', (string) new ContractException('odd', 502));
        self::assertSame(
            '[transport.timeout] slow (request_id=r-1)',
            (new TransportException('transport.timeout', 'slow'))->withRequestId('r-1')->getMessage()
        );
    }

    public function testDetailsKeepOnlyStringValues(): void
    {
        $err = ApiException::from(403, [
            'code' => 'cli.permission_denied',
            'message' => 'no',
            'retryable' => false,
            'details' => ['required_role' => 'finance', 'role' => 'viewer', 'n' => 3, 'x' => null],
        ]);

        self::assertSame(['required_role' => 'finance', 'role' => 'viewer'], $err->details);
        $json = json_decode((string) json_encode($err), true);
        self::assertIsArray($json);
        self::assertSame(['required_role' => 'finance', 'role' => 'viewer'], $json['details']);
        self::assertNull(ApiException::from(403, ['code' => 'cli.permission_denied', 'details' => ['finance']])->details);
        self::assertNull(ApiException::from(403, ['code' => 'cli.permission_denied'])->details);
    }

    public function testJsonKeepsTheBareTextAsTheMessage(): void
    {
        $err = ApiException::from(400, ['code' => 'payment.bad_amount', 'message' => 'bad', 'request_id' => 'rq-9']);
        $json = json_decode((string) json_encode($err), true);

        self::assertIsArray($json);
        self::assertSame('bad', $json['message']);
        self::assertSame('rq-9', $json['requestId']);
    }
}
