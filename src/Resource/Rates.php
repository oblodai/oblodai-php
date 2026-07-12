<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Публичные справочники: курсы валют и каталог монет/сетей (подпись не требуется).
 */
final class Rates extends AbstractResource
{
    /**
     * Курсы к USDT. POST /v1/exchange-rate/list
     *
     * @return array<int,array{from:string,to:string,course:string}>
     */
    public function list(?string $currencyFrom = null): array
    {
        $body = $currencyFrom !== null ? ['currency_from' => $currencyFrom] : [];

        return $this->client->requestPublic('/v1/exchange-rate/list', $body);
    }

    /**
     * Каталог принимаемых активов и сетей. GET /v1/currencies (публичный, без подписи).
     *
     * @return array<int,array<string,mixed>>
     */
    public function currencies(): array
    {
        $res = $this->client->requestPublic('/v1/currencies', [], 'GET');

        return is_array($res) && isset($res['currencies']) ? $res['currencies'] : [];
    }
}
