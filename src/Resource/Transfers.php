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
     * Codes worth branching on: `transfer.bad_amount`, `merchant.no_owner`,
     * `merchant.no_personal_wallet`, `payout.insufficient_funds` (retryable),
     * `payout.funds_maturing` (retryable), `merchant.wrong_key_kind`.
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
     * Codes worth branching on: `transfer.bad_amount`, `transfer.no_recipient`,
     * `transfer.recipient_not_found`, `transfer.bad_recipient` (the recipient is yourself),
     * `payout.insufficient_funds` (retryable), `merchant.wrong_key_kind`.
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
     * Codes worth branching on: `payout.batch_too_large`, `payout.empty_batch`,
     * `request.missing_field` (an item without `order_id`/`amount`/`currency`),
     * `transfer.recipient_not_found`, `merchant.wrong_key_kind`, `idempotency.key_reused`.
     *
     * @param array<string, mixed>|TransferBatchRequest $params
     */
    public function batch(array|TransferBatchRequest $params, ?RequestOptions $options = null): BatchSubmitted
    {
        return $this->call('POST /v1/transfer/batch', $params, $options, BatchSubmitted::fromArray(...));
    }
}
