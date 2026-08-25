<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\Currencies;
use Oblodai\Contract\Model\ExchangeRate;
use Oblodai\Contract\Request\ExchangeRateListRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/** Public reference data — no credentials needed. */
final class Catalog extends Resource
{
    /** `GET /v1/currencies` — every asset, its networks and live availability. */
    public function currencies(?RequestOptions $options = null): Currencies
    {
        return $this->call('GET /v1/currencies', null, $options, Currencies::fromArray(...));
    }

    /**
     * `POST /v1/exchange-rate/list` — current rates, optionally filtered by
     * `currency_from`/`currency_to`.
     *
     * @param  array<string, mixed>|ExchangeRateListRequest $params
     * @return Page<ExchangeRate>
     */
    public function exchangeRates(
        array|ExchangeRateListRequest $params = [],
        ?RequestOptions $options = null,
    ): Page {
        return $this->page('POST /v1/exchange-rate/list', $params, ExchangeRate::fromArray(...), $options);
    }
}
