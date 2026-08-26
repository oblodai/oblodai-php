<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 403 — the key is valid but not allowed to do this (feature disabled, allowlist, approval). */
class PermissionException extends ApiException
{
}
