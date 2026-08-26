<?php

declare(strict_types=1);

/** Emitters for the route registry, the enum vocabularies and the contract stamp. */

/**
 * Read-only routes: a transport failure may be retried without risking a duplicate side effect.
 * The core hand-classifies every route and exports the verdict as `safe`; the SDK never guesses it
 * from the path, so a new write route can never be mistaken for a reader.
 */
function routeIsSafe(array $route): bool
{
    if (!array_key_exists('safe', $route) || !is_bool($route['safe'])) {
        throw new RuntimeException(sprintf(
            'route %s %s has no boolean `safe` field — re-export contract/contract.json from a core '
                . 'that classifies route safety',
            (string) ($route['method'] ?? '?'),
            (string) ($route['path'] ?? '?')
        ));
    }

    return $route['safe'];
}

/**
 * The auth gate of a route, checked against the vocabulary the core exports since the single-key
 * cleanup: `public` (unsigned), `key` (signed with the merchant's one API key) and `onboard`
 * (`X-Admin-Token`). The old split-key values (`payment`, `payout`, `any`) are gone; a contract
 * still carrying them is a stale export and must not be turned into a registry.
 */
function routeAuth(array $route): string
{
    $auth = $route['auth'] ?? null;
    if (!is_string($auth) || !in_array($auth, ['public', 'key', 'onboard'], true)) {
        throw new RuntimeException(sprintf(
            'route %s %s declares auth %s — expected one of public|key|onboard; re-export '
                . 'contract/contract.json from a core with the single API key',
            (string) ($route['method'] ?? '?'),
            (string) ($route['path'] ?? '?'),
            is_string($auth) ? '"' . $auth . '"' : gettype($auth)
        ));
    }

    return $auth;
}

function phpString(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}

/** `paid_over` → `PaidOver`, `invoice.paid` → `InvoicePaid`. */
function pascalCase(string $value): string
{
    $parts = preg_split('/[^a-zA-Z0-9]+/', $value) ?: [];

    return implode('', array_map(static fn (string $p): string => ucfirst(strtolower($p)), array_filter($parts)));
}

function writeGenerated(string $path, string $contents): string
{
    if (!is_file($path) || file_get_contents($path) !== $contents) {
        file_put_contents($path, $contents);
    }

    return $path;
}

function emitRoutes(string $outDir, string $header, array $routes): string
{
    // Field names the contract itself declares as JSON numbers. Everything else that looks numeric
    // on the wire (every amount) is a decimal string, and the request serializer refuses a float
    // for it — 0.1 + 0.2 must never become an amount.
    $numberFields = [];
    foreach ($routes as $r) {
        foreach ((array) ($r['request_schema']['properties'] ?? []) as $field => $property) {
            if (($property['type'] ?? '') === 'number') {
                $numberFields[(string) $field] = true;
            }
        }
    }
    $numberFields = array_keys($numberFields);
    sort($numberFields);

    $lines = [];
    foreach ($routes as $r) {
        $lines[] = sprintf(
            "        %s => ['method' => %s, 'path' => %s, 'auth' => %s, 'idempotent' => %s, "
                . "'safe' => %s, 'bare' => %s, 'list' => %s],",
            phpString($r['method'] . ' ' . $r['path']),
            phpString($r['method']),
            phpString($r['path']),
            phpString(routeAuth($r)),
            $r['idempotent'] ? 'true' : 'false',
            routeIsSafe($r) ? 'true' : 'false',
            $r['bare'] ? 'true' : 'false',
            isset($r['list']) && $r['list'] !== '' ? phpString((string) $r['list']) : 'null'
        );
    }

    $template = <<<'PHP'
        <?php

        declare(strict_types=1);

        %HEADER%
        namespace Oblodai\Contract;

        use Oblodai\Exception\ConfigException;

        /**
         * Every merchant-facing route the core declares, keyed exactly as its conformance table keys them.
         *
         * @phpstan-type RouteArray array{method: string, path: string, auth: string, idempotent: bool, safe: bool, bare: bool, list: string|null}
         */
        final class Routes
        {
            /** @var array<string, RouteArray> */
            public const SPECS = [
        %SPECS%
            ];

            /**
             * Request fields the contract declares as JSON numbers. Every other numeric-looking field
             * is a decimal string; `RequestBuilder::serializeBody()` rejects a float for it.
             *
             * @var list<string>
             */
            public const NUMBER_FIELDS = [%NUMBERS%];

            /** @var array<string, RouteSpec> */
            private static array $cache = [];

            /** Route spec by its `METHOD /path` key. */
            public static function get(string $key): RouteSpec
            {
                if (isset(self::$cache[$key])) {
                    return self::$cache[$key];
                }
                $spec = self::SPECS[$key] ?? null;
                if ($spec === null) {
                    throw new ConfigException('sdk.bad_config', sprintf('unknown route "%s"', $key));
                }

                return self::$cache[$key] = new RouteSpec(
                    $spec['method'],
                    $spec['path'],
                    $spec['auth'],
                    $spec['idempotent'],
                    $spec['safe'],
                    $spec['bare'],
                    $spec['list'],
                );
            }

            /** @return list<string> */
            public static function keys(): array
            {
                return array_keys(self::SPECS);
            }
        }

        PHP;

    return writeGenerated($outDir . '/Routes.php', strtr($template, [
        '%HEADER%' => $header,
        '%SPECS%' => implode("\n", $lines),
        '%NUMBERS%' => implode(', ', array_map('phpString', $numberFields)),
    ]));
}

/** @return list<string> paths written */
function emitEnums(string $outDir, string $header, array $contract): array
{
    $specs = [
        ['payment_status', 'PaymentStatus', 'PAYMENT_STATUSES', 'Invoice lifecycle statuses.'],
        ['payout_status', 'PayoutStatus', 'PAYOUT_STATUSES', 'Payout lifecycle statuses.'],
        ['payout_link_status', 'PayoutLinkStatus', 'PAYOUT_LINK_STATUSES', 'Payout-link (cheque) statuses.'],
        ['delivery_status', 'DeliveryStatus', 'DELIVERY_STATUSES', 'Webhook delivery statuses.'],
        ['network', 'Network', 'NETWORKS', 'Blockchain networks the gateway settles on.'],
        ['fee_bearer', 'FeeBearer', 'FEE_BEARERS', 'Who is asked to bear the network fee.'],
        ['fee_bearer_result', 'FeeBearerResult', 'FEE_BEARER_RESULTS', 'Who actually bore the network fee.'],
        ['batch_on_error', 'BatchOnError', 'BATCH_ON_ERRORS', 'What an asynchronous batch does after a failed row.'],
        ['webhook_kind', 'WebhookKind', 'WEBHOOK_KINDS', 'Kinds of test webhook the core can deliver.'],
        ['error_kind', 'ErrorKind', 'ERROR_KINDS', 'Error families as the core classifies them by HTTP status.'],
        // Vocabularies the core does not export as enums yet; pinned here from its handlers.
        ['@amount_mode', 'AmountMode', 'AMOUNT_MODES', 'How a payment link prices its invoices.'],
        ['@event_type', 'EventType', 'EVENT_TYPES', 'Webhook event types: `invoice.<status>`, `payout.<status>`, `wallet.paid`.'],
    ];

    $enumTemplate = <<<'PHP'
        <?php

        declare(strict_types=1);

        %HEADER%
        namespace Oblodai\Contract\Enum;

        /** %DOC% */
        enum %NAME%: string
        {
        %CASES%
        }

        PHP;

    $written = [];
    $constants = [];
    foreach ($specs as [$key, $name, $const, $doc]) {
        $values = match ($key) {
            '@amount_mode' => ['fixed', 'open', 'range'],
            '@event_type' => $contract['event_types'],
            default => $contract['enums'][$key] ?? throw new RuntimeException("enum {$key} missing from contract.json"),
        };
        $cases = array_map(
            static fn ($v): string => sprintf('    case %s = %s;', pascalCase((string) $v), phpString((string) $v)),
            $values
        );
        $written[] = writeGenerated($outDir . '/Enum/' . $name . '.php', strtr($enumTemplate, [
            '%HEADER%' => $header,
            '%DOC%' => $doc,
            '%NAME%' => $name,
            '%CASES%' => implode("\n", $cases),
        ]));
        $constants[] = [$const, $doc, $values];
    }
    $constants[] = ['ERROR_CODES', 'Every error code the core source can emit (`family.reason`).', $contract['error_codes']];

    $blocks = [];
    foreach ($constants as [$const, $doc, $values]) {
        $lines = [];
        $current = '       ';
        foreach ($values as $value) {
            $item = phpString((string) $value);
            if (strlen($current) + strlen($item) + 2 > 116) {
                $lines[] = $current;
                $current = '       ';
            }
            $current .= ' ' . $item . ',';
        }
        $lines[] = $current;
        $blocks[] = sprintf(
            "    /**\n     * %s\n     *\n     * @var list<string>\n     */\n    public const %s = [\n%s\n    ];",
            $doc,
            $const,
            implode("\n", $lines)
        );
    }

    $enumsTemplate = <<<'PHP'
        <?php

        declare(strict_types=1);

        %HEADER%
        namespace Oblodai\Contract;

        /**
         * The wire vocabularies as flat lists — for validation, tests and anything that needs the raw
         * strings. The same values are available as PHP enums under `Oblodai\Contract\Enum`.
         */
        final class Enums
        {
        %BLOCKS%
        }

        PHP;

    $written[] = writeGenerated($outDir . '/Enums.php', strtr($enumsTemplate, [
        '%HEADER%' => $header,
        '%BLOCKS%' => implode("\n\n", $blocks),
    ]));

    return $written;
}

function emitVersion(string $outDir, string $header, array $contract, string $raw): string
{
    $template = <<<'PHP'
        <?php

        declare(strict_types=1);

        %HEADER%
        namespace Oblodai\Contract;

        /** Which contract snapshot these generated files came from. */
        final class Version
        {
            public const CORE_COMMIT = %COMMIT%;
            public const EXPORTED_AT = %EXPORTED%;
            public const CONTRACT_HASH = %HASH%;
        }

        PHP;

    return writeGenerated($outDir . '/Version.php', strtr($template, [
        '%HEADER%' => $header,
        '%COMMIT%' => phpString((string) $contract['core_commit']),
        '%EXPORTED%' => phpString((string) $contract['exported_at']),
        '%HASH%' => phpString(hash('sha256', $raw)),
    ]));
}
