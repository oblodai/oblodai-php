<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\Payout;
use Oblodai\Contract\Model\Wallet;
use Oblodai\Contract\Model\WalletBlocked;
use Oblodai\Contract\Model\WalletQr;
use Oblodai\Contract\Request\WalletBlockedAddressRefundRequest;
use Oblodai\Contract\Request\WalletBlockRequest;
use Oblodai\Contract\Request\WalletRequest;
use Oblodai\Core\RequestOptions;

/** Static deposit wallets: one permanent address per customer, deposits reported as `wallet.paid`. */
final class Wallets extends Resource
{
    /**
     * `POST /v1/wallet` — a permanent deposit address, idempotent by `order_id`.
     *
     * Codes worth branching on: `wallet.static_disabled`, `wallet.unsupported_network`,
     * `wallet.no_network` (multi-network asset, no `network` given), `wallet.no_address`
     * (derivation is temporarily unavailable — retryable), `wallet.sandbox_unsupported`,
     * `request.unknown_currency`, `idempotency.key_reused`.
     *
     * @param array<string, mixed>|WalletRequest $params
     */
    public function create(array|WalletRequest $params, ?RequestOptions $options = null): Wallet
    {
        return $this->call('POST /v1/wallet', $params, $options, Wallet::fromArray(...));
    }

    /** `POST /v1/wallet/qr`. */
    public function qr(string $address, ?RequestOptions $options = null): WalletQr
    {
        return $this->call('POST /v1/wallet/qr', ['address' => $address], $options, WalletQr::fromArray(...));
    }

    /**
     * `POST /v1/wallet/block` — stop crediting an address; later deposits wait for a refund decision.
     *
     * @param array<string, mixed>|WalletBlockRequest $params
     */
    public function block(array|WalletBlockRequest $params, ?RequestOptions $options = null): WalletBlocked
    {
        return $this->call('POST /v1/wallet/block', $params, $options, WalletBlocked::fromArray(...));
    }

    /**
     * `POST /v1/wallet/blocked-address-refund` — send funds that landed on a blocked address back.
     * Payout key.
     *
     * Codes worth branching on: `wallet.bad_uuid` (unknown wallet), `refund.no_address` (the address
     * is not blocked), `refund.nothing_to_refund` (already refunded or empty), `refund.dust` (below the
     * network minimum), `refund.destination_internal`, `merchant.wrong_key_kind`.
     */
    public function refundBlockedDeposit(
        array|WalletBlockedAddressRefundRequest $params,
        ?RequestOptions $options = null,
    ): Payout {
        return $this->call('POST /v1/wallet/blocked-address-refund', $params, $options, Payout::fromArray(...));
    }
}
