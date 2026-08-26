<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Webhook delivery statuses. */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Dead = 'dead';
}
