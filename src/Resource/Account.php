<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\Balance;
use Oblodai\Contract\Model\ReferralInfo;
use Oblodai\Contract\Model\VrcsStatus;
use Oblodai\Core\RequestOptions;

/** Balances and account-level facts. */
final class Account extends Resource
{
    /** `POST /v1/balance` — available balance per currency. */
    public function balance(?RequestOptions $options = null): Balance
    {
        return $this->call('POST /v1/balance', null, $options, Balance::fromArray(...));
    }

    /** `POST /v1/referral/info` — referral code, link and earnings. */
    public function referral(?RequestOptions $options = null): ReferralInfo
    {
        return $this->call('POST /v1/referral/info', null, $options, ReferralInfo::fromArray(...));
    }

    /**
     * `POST /v1/vrcs` — read (no argument) or set volatility-risk conversion (auto-convert volatile
     * deposits to USDT).
     */
    public function vrcs(?bool $enabled = null, ?RequestOptions $options = null): VrcsStatus
    {
        return $this->call(
            'POST /v1/vrcs',
            $enabled === null ? null : ['enabled' => $enabled],
            $options,
            VrcsStatus::fromArray(...)
        );
    }
}
