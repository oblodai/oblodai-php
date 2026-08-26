<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Kinds of test webhook the core can deliver. */
enum WebhookKind: string
{
    case Payment = 'payment';
    case Payout = 'payout';
    case Wallet = 'wallet';
}
