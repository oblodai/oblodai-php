<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** What an asynchronous batch does after a failed row. */
enum BatchOnError: string
{
    case Continue = 'continue';
    case Stop = 'stop';
}
