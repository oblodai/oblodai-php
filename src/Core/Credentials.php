<?php

declare(strict_types=1);

namespace Oblodai\Core;

/** An API key pair: the public id travels in `X-Public-Id`, the secret only ever signs. */
final class Credentials
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $secret,
    ) {
    }
}
