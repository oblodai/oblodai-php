<?php

// Deliberately WITHOUT declare(strict_types=1): most merchant code (a Laravel app, a plain script)
// runs in PHP's coercive mode, where a float given to a `string` parameter silently becomes a
// string. The SDK must still refuse a float amount here (spec §3 item 3). Excluded from the
// php-cs-fixer rule that would add the declare.

namespace Oblodai\Tests\Support;

use Oblodai\Generated\Model\PaymentRequest;

/** Builds a request model the way coercive-mode caller code would, with a float amount. */
function paymentRequestWithFloatAmount(float $amount): PaymentRequest
{
    return new PaymentRequest(amount: $amount, currency: 'USDT');
}
