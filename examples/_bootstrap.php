<?php

declare(strict_types=1);

/**
 * Shared setup for the examples: load the autoloader, fail closed when the keys are missing, and
 * build a client from the environment.
 *
 * Every example dies with one readable line — not a stack trace — when a key is absent, and none of
 * them turns off the https requirement behind your back: set `OBLODAI_ALLOW_INSECURE=1` yourself
 * when you point one at a local gateway over plain http.
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Exception\OblodaiException;
use Oblodai\Oblodai;

/** @param list<string> $required environment variables the example cannot run without */
function example_client(array $required = ['OBLODAI_PUBLIC_ID', 'OBLODAI_SECRET']): Oblodai
{
    $missing = array_values(array_filter(
        $required,
        static fn (string $name): bool => (string) getenv($name) === ''
    ));
    if ($missing !== []) {
        example_die(sprintf(
            "set %s before running this example (keys come from the Oblodai dashboard;\n"
                . 'a sandbox key looks like test_…)',
            implode(', ', $missing)
        ));
    }

    try {
        return new Oblodai(baseUrl: getenv('OBLODAI_BASE_URL') ?: null);
    } catch (OblodaiException $err) {
        example_die($err->getMessage() . ' (' . $err->errorCode . ')');
    }
}

/** Print one line to STDERR and stop. Examples never dump a stack trace at a reader. */
function example_die(string $message): never
{
    fwrite(STDERR, $message . "\n");

    exit(1);
}

/** Report an API failure the way a caller should read it: code first, then the gateway's words. */
function example_fail(string $what, OblodaiException $err): never
{
    example_die(sprintf(
        '%s: %s (%s%s%s)',
        $what,
        $err->getMessage(),
        $err->errorCode,
        $err->retryable ? ', retryable' : '',
        $err->requestId !== null ? ', request ' . $err->requestId : ''
    ));
}
