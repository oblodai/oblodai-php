<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** Webhook verification failed (bad signature, stale timestamp, missing headers). */
class SignatureException extends OblodaiException
{
    public const BAD_SIGNATURE = 'webhook.bad_signature';
    public const STALE_TIMESTAMP = 'webhook.stale_timestamp';
    public const MISSING_HEADER = 'webhook.missing_header';

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct(errorCode: $errorCode, message: $message, httpStatus: 0, retryable: false);
    }
}
