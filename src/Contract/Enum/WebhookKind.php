<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Kinds of test webhook the core can deliver. */
enum WebhookKind: string
{
    case Payment = 'payment';
    case Payout = 'payout';
    case Wallet = 'wallet';
}
