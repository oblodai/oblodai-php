<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** Raised before any request is sent: bad options, missing credentials, unusable arguments. */
class ConfigException extends OblodaiException
{
    public const MISSING_CREDENTIALS = 'sdk.missing_credentials';
    public const BAD_CONFIG = 'sdk.bad_config';
    public const IDEMPOTENCY_UNSUPPORTED = 'sdk.idempotency_unsupported';
    public const BAD_PATH_PARAM = 'sdk.bad_path_param';
    public const BAD_IDEMPOTENCY_KEY = 'sdk.bad_idempotency_key';
    public const BAD_AMOUNT = 'sdk.bad_amount';
    public const BAD_HEADER = 'sdk.bad_header';

    public function __construct(string $errorCode, string $message, ?string $field = null)
    {
        parent::__construct(
            errorCode: $errorCode,
            message: $message,
            httpStatus: 0,
            retryable: false,
            field: $field,
        );
    }
}
