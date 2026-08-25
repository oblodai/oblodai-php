<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** 400 — the request is malformed or violates a business rule; see `field` and `errorCode`. */
class ValidationException extends ApiException
{
}
