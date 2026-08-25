<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchSubmitted;
use Oblodai\Contract\Model\TransferToPersonal;
use Oblodai\Contract\Model\TransferToUser;
use Oblodai\Contract\Request\TransferBatchRequest;
use Oblodai\Contract\Request\TransferToPersonalRequest;
use Oblodai\Contract\Request\TransferToUserRequest;
use Oblodai\Core\RequestOptions;

/** Internal, instant, fee-free moves between platform balances. Payout key. */
final class Transfers extends Resource
{
    /**
     * `POST /v1/transfer/to-personal` — business balance → the owner's personal wallet (needs an
     * owner link).
     *
     * @param array<string, mixed>|TransferToPersonalRequest $params
     */
    public function toPersonal(
        array|TransferToPersonalRequest $params,
        ?RequestOptions $options = null,
    ): TransferToPersonal {
        return $this->call('POST /v1/transfer/to-personal', $params, $options, TransferToPersonal::fromArray(...));
    }

    /**
     * `POST /v1/transfer/to-user` — business balance → another platform user's personal wallet.
     * `amount` and `currency` are required.
     *
     * @param array<string, mixed>|TransferToUserRequest $params
     */
    public function toUser(
        array|TransferToUserRequest $params,
        ?RequestOptions $options = null,
    ): TransferToUser {
        return $this->call('POST /v1/transfer/to-user', $params, $options, TransferToUser::fromArray(...));
    }

    /**
     * `POST /v1/transfer/batch` — ASYNCHRONOUS batch of `toUser` transfers; poll `batches->info()`.
     * `order_id` is required on every item.
     *
     * @param array<string, mixed>|TransferBatchRequest $params
     */
    public function batch(array|TransferBatchRequest $params, ?RequestOptions $options = null): BatchSubmitted
    {
        return $this->call('POST /v1/transfer/batch', $params, $options, BatchSubmitted::fromArray(...));
    }
}
