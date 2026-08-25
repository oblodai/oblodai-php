<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 503 — an upstream dependency is down; safe to retry after a pause. */
class UnavailableException extends ApiException
{
}
