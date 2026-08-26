<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Error families as the core classifies them by HTTP status. */
enum ErrorKind: string
{
    case Invalid = 'invalid';
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case Internal = 'internal';
}
