<?php

declare(strict_types=1);

namespace Oblodai\Contract;

/**
 * One row of the route registry (src/Contract/Routes.php, generated from contract/contract.json,
 * which the core exports from its own conformance table). Every route the SDK can call is one the
 * core declares, with the same auth gate and the same idempotency wrapper.
 */
final class RouteSpec
{
    public function __construct(
        /** Upper-case HTTP method. */
        public readonly string $method,
        /** Path template; `{name}` segments are filled from path parameters. */
        public readonly string $path,
        /** Which credential the core's gate expects: `public`, `key` or `onboard`. */
        public readonly string $auth,
        /** Wrapped in the core's `withIdempotency`: a key is generated when the caller sends none. */
        public readonly bool $idempotent,
        /** Read-only: a transport failure may be retried without risking a duplicate side effect. */
        public readonly bool $safe,
        /** Outside the JSON envelope (binary documents). */
        public readonly bool $bare,
        /** `paged` ({items, paginate}), `plain` ({items}) or null. */
        public readonly ?string $list = null,
    ) {
    }

    /** `POST /v1/payment` — how the registry keys this route. */
    public function key(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
