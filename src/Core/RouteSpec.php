<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * One operation of the API as the runtime needs to know it. The generated route table
 * (`Oblodai\Generated\Routes`) builds one per `operationId` of the gateway's OpenAPI contract.
 */
final class RouteSpec
{
    public function __construct(
        /** The OpenAPI `operationId`, e.g. `createPayment`. */
        public readonly string $operationId,
        /** Upper-case HTTP method. */
        public readonly string $method,
        /** Path template; `{name}` segments are filled from path parameters. */
        public readonly string $path,
        /** Which credential the gateway expects: `public`, `key` or `onboard`. */
        public readonly string $auth,
        /** Deduplicated by `Idempotency-Key`: a key is generated when the caller sends none. */
        public readonly bool $idempotent,
        /** Free of side effects: a failed attempt may be repeated without a key. */
        public readonly bool $safe,
        /** Outside the JSON envelope: the answer is a file (PDF/CSV). */
        public readonly bool $bare,
        /** `paged` for `{items, paginate}` lists, else null. */
        public readonly ?string $listKind = null,
    ) {
    }

    /** `POST /v1/payment` — the method and path of this route. */
    public function key(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
