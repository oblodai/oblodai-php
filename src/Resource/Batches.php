<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchInfo;
use Oblodai\Contract\Request\BatchInfoRequest;
use Oblodai\Core\RequestOptions;

/** Progress of asynchronous batches (payment, refund, payout, transfer, payout-link). */
final class Batches extends Resource
{
    /**
     * `POST /v1/batch/info` — status, counters and per-row outcomes, for a batch of any kind.
     *
     * @param array<string, mixed>|BatchInfoRequest $params
     */
    public function info(array|BatchInfoRequest $params, ?RequestOptions $options = null): BatchInfo
    {
        return $this->call('POST /v1/batch/info', $params, $options, BatchInfo::fromArray(...));
    }
}
