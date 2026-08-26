<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Payout lifecycle statuses. */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case AwaitingCosign = 'awaiting_cosign';
    case Broadcasting = 'broadcasting';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
