<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 409 `idempotency.key_reused` — the same key was used with a different request body. */
class IdempotencyConflictException extends ConflictException
{
}
