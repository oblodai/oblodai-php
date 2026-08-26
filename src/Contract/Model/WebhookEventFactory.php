<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Exception\WebhookPayloadException;

/**
 * Dispatches a decoded webhook body to the right {@see WebhookEvent} implementation, by `type`.
 *
 * A `type` this release does not know is NOT an error: the delivery was signed by the gateway, so
 * it is handed back as an {@see UnknownEvent} carrying the raw body. Only a body that is not a JSON
 * object at all, or has no usable `type`, is refused — as `webhook.bad_payload`, a contract error
 * rather than a signature one.
 */
final class WebhookEventFactory
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): WebhookEvent
    {
        $type = Wire::nullableStr($data, 'type');

        if ($type === null || $type === '') {
            throw new WebhookPayloadException('webhook body has no `type` discriminant', $data);
        }

        return match ($type) {
            'payment' => PaymentEvent::fromArray($data),
            'payout' => PayoutEvent::fromArray($data),
            'wallet' => WalletEvent::fromArray($data),
            default => UnknownEvent::fromArray($data),
        };
    }
}
