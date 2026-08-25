<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Exception\SignatureException;

/** Dispatches a decoded webhook body to the right {@see WebhookEvent} implementation, by `type`. */
final class WebhookEventFactory
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): WebhookEvent
    {
        $type = Wire::nullableStr($data, 'type');
        $uuid = Wire::nullableStr($data, 'uuid');

        if ($type === null || $uuid === null) {
            throw new SignatureException(
                SignatureException::BAD_SIGNATURE,
                sprintf('unknown event type "%s"', $type ?? '')
            );
        }

        return match ($type) {
            'payment' => PaymentEvent::fromArray($data),
            'payout' => PayoutEvent::fromArray($data),
            'wallet' => WalletEvent::fromArray($data),
            default => throw new SignatureException(
                SignatureException::BAD_SIGNATURE,
                sprintf('unknown event type "%s"', $type)
            ),
        };
    }
}
