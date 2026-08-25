<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 429 — rate limited; `retryAfter` is set. */
class RateLimitException extends ApiException
{
}
