<?php

declare(strict_types=1);

namespace Oblodai\Core;

use JsonSerializable;

/**
 * An API key pair: the public id travels in `X-Public-Id`, the secret only ever signs.
 *
 * The secret is held as a {@see Secret}, so no dump of the client, the config or the transport —
 * `var_dump`, `print_r`, `json_encode`, `serialize`, or a logger that renders its context — can
 * carry it into a log file. `secret()` returns the bytes; only {@see Signer} needs them.
 */
final class Credentials implements JsonSerializable
{
    private readonly Secret $key;

    public function __construct(
        public readonly string $publicId,
        Secret|string $secret,
    ) {
        $this->key = $secret instanceof Secret ? $secret : new Secret($secret);
    }

    /** The signing secret. */
    public function secret(): string
    {
        return $this->key->reveal();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['publicId' => $this->publicId, 'secret' => Secret::REDACTED];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['publicId' => $this->publicId, 'secret' => Secret::REDACTED];
    }
}
