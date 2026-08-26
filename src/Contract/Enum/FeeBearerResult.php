<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Who actually bore the network fee. */
enum FeeBearerResult: string
{
    case Recipient = 'recipient';
    case Merchant = 'merchant';
    case Gateway = 'gateway';
}
