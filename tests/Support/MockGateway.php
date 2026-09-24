<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use Oblodai\Generated\Routes;
use RuntimeException;

/**
 * The mock gateway behind tests/Support/mock-gateway.php, and the helpers that start it (with the
 * PHP built-in web server) for the README and examples tests.
 */
final class MockGateway
{
    /**
     * operationId => wire fields set on its generated answer, so a script takes its main path.
     */
    public const SHAPES = [
        'getPaymentInfo' => ['status' => 'paid'],
        'validatePayout' => ['valid' => true],
        'getBalance' => ['balance' => ['merchant' => [['balance' => '1000', 'currency' => 'USDT']]]],
        'calculatePayout' => ['currency' => 'USDT', 'payer_amount' => '11', 'commission' => '1'],
        'getBatchInfo' => ['status' => 'completed'],
        'getDocumentJob' => ['status' => 'done'],
    ];

    /** Answer the current request (inside the built-in server). */
    public static function serve(): void
    {
        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        header('X-Request-ID: mock-' . substr(sha1($method . $uri . microtime()), 0, 8));
        $operationId = self::operationFor($method, $path);
        if ($operationId === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => ['code' => 'route.not_found', 'message' => "no route {$method} {$path}", 'retryable' => false]]);

            return;
        }
        if (Routes::get($operationId)->bare) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="document.pdf"');
            echo '%PDF-1.7 mock';

            return;
        }
        $result = Operations::sampleResult($operationId, self::SHAPES[$operationId] ?? []);
        header('Content-Type: application/json');
        echo json_encode(['state' => 0, 'result' => $result]);
    }

    /** The operation a request is for, matched against the generated route table. */
    public static function operationFor(string $method, string $path): ?string
    {
        foreach (Routes::all() as $operationId => $route) {
            $pattern = '#^' . preg_replace('#\\\\\{[^}]*\\\\\}#', '[^/]+', preg_quote($route->path, '#')) . '$#';
            if ($route->method === $method && preg_match($pattern, $path) === 1) {
                return $operationId;
            }
        }

        return null;
    }

    /**
     * Start `php -S` on a free port with a router script; returns the process and its base URL.
     *
     * @param array<string, string> $env
     *
     * @return array{0: resource, 1: string}
     */
    public static function start(string $router, array $env = []): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('no free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env + self::environment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start php -S');
        }
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($probe !== false) {
                fclose($probe);

                return [$process, 'http://127.0.0.1:' . $port];
            }
            usleep(50_000);
        }
        proc_terminate($process);

        throw new RuntimeException('php -S did not start on port ' . $port);
    }

    /** @param resource $process */
    public static function stop($process): void
    {
        proc_terminate($process);
        proc_close($process);
    }

    /**
     * Run a PHP script in a child process: exit code, stdout, stderr.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public static function run(array $args, array $env): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env + self::environment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start php');
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * The environment a child starts from: this process's, minus any Oblodai setting that would
     * point the child somewhere else.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $env = [];
        foreach (getenv() as $name => $value) {
            if (!str_starts_with($name, 'OBLODAI_')) {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
