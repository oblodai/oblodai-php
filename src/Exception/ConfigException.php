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
    /** A float where the gateway expects a decimal string: an amount would lose precision. */
    public const FLOAT_AMOUNT = 'sdk.float_amount';
    /** Operator-only route: the operator HMAC channel is not implemented by the SDK. */
    public const OPERATOR_CHANNEL_UNSUPPORTED = 'sdk.operator_channel_unsupported';
    /** A request body larger than the contract's MAX_BODY. */
    public const BODY_TOO_LARGE = 'sdk.body_too_large';
    /** A local file the SDK was asked to write already exists. */
    public const FILE_EXISTS = 'sdk.file_exists';
    /** A long-running operation could not be followed (no route or id to poll). */
    public const LRO_UNRESOLVED = 'sdk.lro_unresolved';

    /**
     * The `sdk.float_amount` error for a float found at `$field` (a wire name or a body path):
     * amounts and rates travel as decimal strings, and the message says which string to pass.
     * One wording for the request-body scan and the generated models' constructors.
     */
    public static function floatAmount(string $field, float $value): self
    {
        return new self(
            self::FLOAT_AMOUNT,
            sprintf(
                '"%s" was given as a float (%s); amounts and rates travel as decimal strings '
                    . "— pass '%s' instead",
                $field,
                var_export($value, true),
                rtrim(rtrim(sprintf('%.18F', $value), '0'), '.')
            ),
            $field
        );
    }

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
