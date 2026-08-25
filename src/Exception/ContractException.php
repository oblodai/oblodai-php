<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** The response could not be interpreted as the documented envelope. */
class ContractException extends OblodaiException
{
    public const BAD_ENVELOPE = 'sdk.bad_envelope';

    public function __construct(string $message, int $httpStatus = 0, mixed $raw = null)
    {
        parent::__construct(
            errorCode: self::BAD_ENVELOPE,
            message: $message,
            httpStatus: $httpStatus,
            retryable: false,
            raw: $raw,
        );
    }
}
