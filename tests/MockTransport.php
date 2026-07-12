<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Http\Response;
use Oblodai\Http\Transport;

/**
 * Транспорт-заглушка: отдаёт заранее заданные ответы по очереди и запоминает запросы.
 */
final class MockTransport implements Transport
{
    /** @var array<int,Response> */
    private array $responses;

    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    /**
     * @param array<int,Response> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}
