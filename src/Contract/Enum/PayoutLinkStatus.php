<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Payout-link (cheque) statuses. */
enum PayoutLinkStatus: string
{
    case Funded = 'funded';
    case Claiming = 'claiming';
    case Claimed = 'claimed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
