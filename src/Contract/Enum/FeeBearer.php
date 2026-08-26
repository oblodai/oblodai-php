<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Who is asked to bear the network fee. */
enum FeeBearer: string
{
    case Recipient = 'recipient';
    case Merchant = 'merchant';
}
