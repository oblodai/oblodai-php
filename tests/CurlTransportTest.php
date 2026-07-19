<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Http\CurlTransport;
use PHPUnit\Framework\TestCase;

/**
 * Разбор `Retry-After` в cURL-транспорте и бюджет таймаутов.
 *
 * RFC 7231 допускает две формы заголовка — delta-seconds и HTTP-date. Раньше SDK понимал только
 * первую, а вторую молча выбрасывал (откатываясь на собственный backoff, то есть повторяя раньше,
 * чем просил сервер).
 */
final class CurlTransportTest extends TestCase
{
    /** Форма delta-seconds — как её отдаёт сам шлюз на 429. */
    public function testParsesDeltaSeconds(): void
    {
        self::assertSame(60.0, CurlTransport::parseRetryAfter('60'));
        self::assertSame(1.5, CurlTransport::parseRetryAfter(' 1.5 '));
        self::assertSame(0.0, CurlTransport::parseRetryAfter('0'));
    }

    /** Форма HTTP-date переводится в остаток относительно «сейчас». */
    public function testParsesHttpDate(): void
    {
        $now = strtotime('Wed, 21 Oct 2026 07:28:00 GMT');
        self::assertIsInt($now);

        self::assertSame(
            120.0,
            CurlTransport::parseRetryAfter('Wed, 21 Oct 2026 07:30:00 GMT', $now),
        );
    }

    /** Прочие даты того же RFC (RFC 850, asctime) тоже понимаются, а не отбрасываются. */
    public function testParsesOtherDateSpellings(): void
    {
        $now = strtotime('Wed, 21 Oct 2026 07:28:00 GMT');
        self::assertIsInt($now);

        self::assertSame(32.0, CurlTransport::parseRetryAfter('2026-10-21T07:28:32Z', $now));
    }

    /** Дата в прошлом — это «можно сразу», а не отрицательная пауза. */
    public function testPastDateClampsToZero(): void
    {
        $now = strtotime('Wed, 21 Oct 2026 07:28:00 GMT');
        self::assertIsInt($now);

        self::assertSame(0.0, CurlTransport::parseRetryAfter('Wed, 21 Oct 2026 07:00:00 GMT', $now));
        self::assertSame(0.0, CurlTransport::parseRetryAfter('-30'));
    }

    /** Абсурдные значения обеих форм зажаты потолком в 300 секунд — как в остальных SDK. */
    public function testClampsToCeiling(): void
    {
        $now = strtotime('Wed, 21 Oct 2026 07:28:00 GMT');
        self::assertIsInt($now);

        self::assertSame(300.0, CurlTransport::parseRetryAfter('86400'));
        self::assertSame(300.0, CurlTransport::parseRetryAfter('Thu, 22 Oct 2026 07:28:00 GMT', $now));
    }

    /** Мусор и пустое значение — null: вызывающий берёт собственный backoff. */
    public function testUnparsableIsNull(): void
    {
        self::assertNull(CurlTransport::parseRetryAfter(''));
        self::assertNull(CurlTransport::parseRetryAfter('   '));
        self::assertNull(CurlTransport::parseRetryAfter('позже'));
    }

    /** Таймаут соединения — примерно треть общего бюджета, чтобы мёртвый хост не съел его весь. */
    public function testConnectTimeoutIsAThirdOfTotal(): void
    {
        self::assertSame(10000, CurlTransport::connectTimeoutMs(30000));
        self::assertSame(1000, CurlTransport::connectTimeoutMs(3000));
        self::assertSame(1, CurlTransport::connectTimeoutMs(1));
    }

    /** 0 в cURL — «без ограничения»; не превращаем его в мгновенный обрыв. */
    public function testUnlimitedTimeoutStaysUnlimited(): void
    {
        self::assertSame(0, CurlTransport::connectTimeoutMs(0));
        self::assertSame(0, CurlTransport::connectTimeoutMs(-5));
    }

    /**
     * Сквозная проверка на живом сокете: транспорт читает `Retry-After` в форме HTTP-date из
     * реального ответа 429 и кладёт в Response уже разобранные секунды. Юнит-проверки выше
     * зовут парсер напрямую — этот тест доказывает, что он подключён к CURLOPT_HEADERFUNCTION.
     */
    public function testSendReadsHttpDateRetryAfterFromRealResponse(): void
    {
        $retryAt = gmdate('D, d M Y H:i:s \G\M\T', time() + 90);
        $portFile = tempnam(sys_get_temp_dir(), 'oblodai-port');
        self::assertIsString($portFile);

        // Одноразовый HTTP-сервер в отдельном процессе: принимает одно соединение и отвечает 429.
        $script = <<<'PHP'
            <?php
            [$portFile, $retryAt] = [$argv[1], $argv[2]];
            $srv = stream_socket_server('tcp://127.0.0.1:0');
            if ($srv === false) { exit(1); }
            file_put_contents($portFile, stream_socket_get_name($srv, false));
            $conn = stream_socket_accept($srv, 10);
            if ($conn === false) { exit(1); }
            fread($conn, 4096);
            fwrite($conn, "HTTP/1.1 429 Too Many Requests\r\nRetry-After: {$retryAt}\r\n"
                . "Content-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
            fclose($conn);
            PHP;
        $scriptFile = tempnam(sys_get_temp_dir(), 'oblodai-srv') . '.php';
        file_put_contents($scriptFile, $script);

        $proc = @proc_open(
            [PHP_BINARY, $scriptFile, $portFile, $retryAt],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            @unlink($scriptFile);
            @unlink($portFile);
            self::markTestSkipped('не удалось запустить вспомогательный процесс');
        }

        // Ждём, пока сервер опубликует адрес (не более ~5 секунд).
        $addr = '';
        for ($i = 0; $i < 500 && $addr === ''; $i++) {
            $addr = trim((string) @file_get_contents($portFile));
            if ($addr === '') {
                usleep(10000);
            }
        }

        try {
            self::assertNotSame('', $addr, 'вспомогательный сервер не поднялся');

            $res = (new CurlTransport(5000))->send('GET', 'http://' . $addr . '/v1/ping', [], null);

            self::assertSame(429, $res->status);
            self::assertNotNull($res->retryAfter);
            self::assertGreaterThan(60.0, $res->retryAfter);
            self::assertLessThanOrEqual(90.0, $res->retryAfter);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($proc);
            proc_close($proc);
            @unlink($scriptFile);
            @unlink($portFile);
        }
    }
}
