<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Enum;

/** Webhook event types: `invoice.<status>`, `payout.<status>`, `wallet.paid`. */
enum EventType: string
{
    case InvoiceSelect = 'invoice.select';
    case InvoiceCreated = 'invoice.created';
    case InvoiceConfirmCheck = 'invoice.confirm_check';
    case InvoicePaid = 'invoice.paid';
    case InvoicePaidOver = 'invoice.paid_over';
    case InvoiceWrongAmount = 'invoice.wrong_amount';
    case InvoiceExpired = 'invoice.expired';
    case InvoiceCancelled = 'invoice.cancelled';
    case PayoutPending = 'payout.pending';
    case PayoutApproved = 'payout.approved';
    case PayoutAwaitingCosign = 'payout.awaiting_cosign';
    case PayoutBroadcasting = 'payout.broadcasting';
    case PayoutSent = 'payout.sent';
    case PayoutConfirmed = 'payout.confirmed';
    case PayoutFailed = 'payout.failed';
    case PayoutCancelled = 'payout.cancelled';
    case WalletPaid = 'wallet.paid';
}
