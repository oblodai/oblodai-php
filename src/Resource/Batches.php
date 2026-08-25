<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchInfo;
use Oblodai\Contract\Request\BatchInfoRequest;
use Oblodai\Core\RequestOptions;
use Oblodai\Exception\PermissionException;

/** Progress of asynchronous batches (payment, refund, payout, transfer, payout-link). */
final class Batches extends Resource
{
    /**
     * `POST /v1/batch/info` — status, counters and per-row outcomes. Accepts either key kind; the
     * core requires the kind that created the batch, so a payout batch is retried with the payout
     * key when one is configured.
     *
     * @param array<string, mixed>|BatchInfoRequest $params
     */
    public function info(array|BatchInfoRequest $params, ?RequestOptions $options = null): BatchInfo
    {
        $options ??= new RequestOptions();

        try {
            return $this->call('POST /v1/batch/info', $params, $options, BatchInfo::fromArray(...));
        } catch (PermissionException $err) {
            if ($err->errorCode !== 'merchant.wrong_key_kind' || $options->preferPayoutKey) {
                throw $err;
            }

            return $this->call(
                'POST /v1/batch/info',
                $params,
                $options->withPreferPayoutKey(),
                BatchInfo::fromArray(...)
            );
        }
    }
}
