<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Invoice lifecycle statuses. */
enum PaymentStatus: string
{
    case Select = 'select';
    case Created = 'created';
    case ConfirmCheck = 'confirm_check';
    case Paid = 'paid';
    case PaidOver = 'paid_over';
    case WrongAmount = 'wrong_amount';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
