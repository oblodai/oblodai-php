<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/**
 * The payload could not be interpreted as the documented shape.
 *
 * `webhook.bad_payload` deliberately lives in this family and not in the signature family: the
 * delivery's MAC was valid, so a receiver that answers 401 on `SignatureException` must not answer
 * 401 here — the event is authentic, only its body is unreadable.
 */
class ContractException extends OblodaiException
{
    public const BAD_ENVELOPE = 'sdk.bad_envelope';

    /** A webhook whose signature verified but whose body is not the documented JSON object. */
    public const BAD_WEBHOOK_PAYLOAD = 'webhook.bad_payload';

    public function __construct(
        string $message,
        int $httpStatus = 0,
        mixed $raw = null,
        string $errorCode = self::BAD_ENVELOPE,
    ) {
        parent::__construct(
            errorCode: $errorCode,
            message: $message,
            httpStatus: $httpStatus,
            retryable: false,
            raw: $raw,
        );
    }
}
