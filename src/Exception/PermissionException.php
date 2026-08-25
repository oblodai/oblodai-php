<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 403 — the key is valid but not allowed to do this (wrong key kind, feature disabled). */
class PermissionException extends ApiException
{
}
