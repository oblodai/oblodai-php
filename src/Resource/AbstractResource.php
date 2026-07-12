<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Client;

abstract class AbstractResource
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }
}
