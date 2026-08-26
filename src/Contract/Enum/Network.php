<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Blockchain networks the gateway settles on. */
enum Network: string
{
    case Ethereum = 'ethereum';
    case Bsc = 'bsc';
    case Polygon = 'polygon';
    case Avalanche = 'avalanche';
    case Base = 'base';
    case Arbitrum = 'arbitrum';
    case Tron = 'tron';
    case Solana = 'solana';
    case Ton = 'ton';
    case Bitcoin = 'bitcoin';
    case Litecoin = 'litecoin';
    case Dogecoin = 'dogecoin';
    case Bitcoincash = 'bitcoincash';
    case Dash = 'dash';
    case Xrp = 'xrp';
    case Stellar = 'stellar';
    case Monero = 'monero';
}
