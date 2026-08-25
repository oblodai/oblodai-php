<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 401 — bad signature, unknown key, clock skew, or the source IP is not allow-listed. */
class AuthenticationException extends ApiException
{
}
