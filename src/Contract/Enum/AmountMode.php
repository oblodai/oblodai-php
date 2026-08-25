<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** How a payment link prices its invoices. */
enum AmountMode: string
{
    case Fixed = 'fixed';
    case Open = 'open';
    case Range = 'range';
}
