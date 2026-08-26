<?php

declare(strict_types=1);

namespace Oblodai\Exception;

use Throwable;

/** The request never produced an HTTP response: DNS, TCP, TLS, timeout, abort, deadline. */
class TransportException extends OblodaiException
{
    public const TIMEOUT = 'transport.timeout';
    public const NETWORK = 'transport.network';
    public const ABORTED = 'transport.aborted';
    public const DEADLINE = 'transport.deadline';

    /**
     * The response body passed the ceiling for its route and was abandoned. Not retryable: the same
     * request would produce the same oversized body.
     */
    public const RESPONSE_TOO_LARGE = 'sdk.response_too_large';

    public function __construct(string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            errorCode: $errorCode,
            message: $message,
            httpStatus: 0,
            retryable: $errorCode === self::TIMEOUT || $errorCode === self::NETWORK,
            previous: $previous,
        );
    }
}
