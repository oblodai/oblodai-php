<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/**
 * A webhook whose signature verified but whose body is not the documented JSON object.
 *
 * It is deliberately a `ContractException` and not a `SignatureException`: the MAC proved the
 * delivery came from the gateway. A receiver that answers 401 on signature failures must not answer
 * 401 here — the event is authentic, only unreadable, and a 401 would have the gateway redeliver it
 * for a day.
 */
final class WebhookPayloadException extends ContractException
{
    public function __construct(string $message, mixed $raw = null)
    {
        parent::__construct($message, 0, $raw, ContractException::BAD_WEBHOOK_PAYLOAD);
    }
}
