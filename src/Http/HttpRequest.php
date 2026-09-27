<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Log\Redactor;

/** One outgoing HTTP request, fully formed: nothing is added below this line. */
final class HttpRequest
{
    /**
     * Largest response body the SDK will read for a JSON route, bytes. Envelopes are small; a body
     * past this is a proxy error page, a misrouted download or a hostile stream, and reading it into
     * a PHP string would take the process down instead of the request.
     */
    public const MAX_JSON_BYTES = 8 * 1024 * 1024;

    /** Largest response body for a `bare` route (PDF/CSV documents), bytes. */
    public const MAX_FILE_BYTES = 64 * 1024 * 1024;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        #[\SensitiveParameter] public readonly string $url,
        #[\SensitiveParameter] public readonly array $headers = [],
        public readonly ?string $body = null,
        /** Hard ceiling on the response body an implementation may buffer, bytes. */
        public readonly int $maxResponseBytes = self::MAX_JSON_BYTES,
        /**
         * The URL as it may be shown in messages and hooks: claim tokens in the path and signed-link
         * parameters masked ({@see \Oblodai\Log\Redactor::url()}). Defaults to a cleaned `$url`.
         */
        ?string $displayUrl = null,
    ) {
        $this->displayUrl = $displayUrl ?? Redactor::url($url);
    }

    public readonly string $displayUrl;
}
