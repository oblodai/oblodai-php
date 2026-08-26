<?php

declare(strict_types=1);

/**
 * Request DTOs: one readonly value object per documented route body, with English field docs from
 * contract/descriptions.en.json so the editor shows what every field means. Resources accept either
 * a DTO or a plain array, so nothing here is mandatory for a caller.
 */

/** Fields drawn from a generated vocabulary: typed `string|Enum` so both forms are accepted. */
const FIELD_ENUMS = [
    'network' => 'Network',
    'pinned_network' => 'Network',
    'on_error' => 'BatchOnError',
    'fee_bearer' => 'FeeBearer',
    'amount_mode' => 'AmountMode',
];
const ROUTE_FIELD_ENUMS = [
    'POST /v1/payment/history#status' => 'PaymentStatus',
    'POST /v1/payout/history#status' => 'PayoutStatus',
    'POST /v1/test-webhook/payment#status' => 'PaymentStatus',
    'POST /v1/test-webhook/payout#status' => 'PayoutStatus',
    'POST /v1/payment/testing-webhook#status' => 'PaymentStatus',
];
/**
 * Fields the docs table marks required although the handler accepts the body without them.
 * Each entry is proved by a recorded fixture in contract/fixtures whose request omits the field and
 * still answered 2xx; tests/Contract/RequestShapeTest.php re-checks that for every route.
 */
const OPTIONAL_OVERRIDES = [
    // The docs table copies `create`'s required list onto the dry run, but a validation needs no
    // order number — contract/fixtures/POST_v1_payout_validate.json omits it and answers 200.
    'POST /v1/payout/validate' => ['order_id'],
];
/** Fields the handler requires although the shared DTO marks them optional. */
const REQUIRED_OVERRIDES = [
    'POST /v1/transfer/to-user' => ['amount', 'currency'],
    'POST /v1/claim/{token}' => ['address'],
];
/** Routes whose body the core does not declare in its docs table. */
const REQUEST_OVERRIDES = [
    'POST /v1/merchants' => [
        'required' => ['email'],
        'properties' => [
            'email' => ['type' => 'string', 'example' => 'owner@shop.example'],
            'name' => ['type' => 'string', 'example' => 'Acme'],
        ],
    ],
];

function requestClassName(array $route): string
{
    $segments = array_filter(
        explode('/', $route['path']),
        static fn (string $s): bool => $s !== '' && $s !== 'v1' && !str_starts_with($s, '{')
    );
    $name = '';
    foreach ($segments as $segment) {
        foreach (preg_split('/[-_]/', $segment) ?: [] as $word) {
            $name .= ucfirst($word);
        }
    }

    return $name . 'Request';
}

function phpTypeOf(array $property, string $route, string $field, array &$imports): string
{
    switch ($property['type'] ?? '') {
        case 'boolean':
            return 'bool';
        case 'integer':
            return 'int';
        case 'number':
            return 'float';
        case 'array':
            return 'array';
        case 'string':
            $enum = ROUTE_FIELD_ENUMS[$route . '#' . $field] ?? FIELD_ENUMS[$field] ?? null;
            if ($enum !== null) {
                $imports[$enum] = true;

                return 'string|' . $enum;
            }

            return 'string';
        default:
            return 'mixed';
    }
}

/** PHPDoc type for the fields PHP's own type system cannot describe. */
function docTypeOf(array $property): ?string
{
    if (($property['type'] ?? '') !== 'array') {
        return null;
    }
    $items = $property['items'] ?? [];

    return match ($items['type'] ?? '') {
        'object' => 'list<array<string, mixed>>|list<\\Oblodai\\Contract\\Request\\RequestBody>',
        'string' => 'list<string>',
        'integer' => 'list<int>',
        'number' => 'list<float>',
        default => 'list<mixed>',
    };
}

/**
 * The example to print for a field, or null.
 *
 * The core's docs table is written in Russian and its examples come with it. Generated code is
 * English-only, so a non-ASCII example is never copied: either contract/descriptions.en.json (which
 * is repo-local, like the field docs) supplies an English one, or the field simply has no example.
 */
function exampleFor(string $route, string $field, array $property, array $descriptions): ?string
{
    $override = $descriptions['example'][$route][$field] ?? null;
    if (is_string($override)) {
        return json_encode($override, JSON_UNESCAPED_SLASHES);
    }
    if (!isset($property['example'])) {
        return null;
    }
    $encoded = json_encode($property['example'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $encoded !== false && preg_match('//u', $encoded) === 1 && strlen($encoded) === mb_strlen($encoded, 'UTF-8')
        ? $encoded
        : null;
}

/** Doc lines for one field: its English description, its example and the shape of nested items. */
function fieldDoc(string $route, string $field, array $property, array $descriptions, array &$missing): array
{
    $text = $descriptions['request'][$route][$field] ?? null;
    if ($text === null && isset($property['description'])) {
        $missing[] = $route . '#' . $field;
    }
    $lines = [];
    if ($text !== null) {
        $lines[] = $text;
    }
    $example = exampleFor($route, $field, $property, $descriptions);
    if ($example !== null) {
        $lines[] = 'Example: ' . $example . '.';
    }
    $items = $property['items'] ?? null;
    if (is_array($items) && ($items['type'] ?? '') === 'object' && isset($items['properties'])) {
        $required = $items['required'] ?? [];
        $names = array_keys($items['properties']);
        sort($names);
        $lines[] = 'Each item:';
        foreach ($names as $name) {
            $nested = $descriptions['request'][$route][$field . '.' . $name] ?? null;
            $lines[] = sprintf(
                '  - `%s`%s%s',
                $name,
                in_array($name, $required, true) ? ' (required)' : '',
                $nested !== null ? ' — ' . $nested : ''
            );
        }
    }

    return $lines;
}

/** @return list<string> paths written */
function emitRequests(string $outDir, string $header, array $routes, array $descriptions): array
{
    $template = <<<'PHP'
        <?php

        declare(strict_types=1);

        %HEADER%
        namespace Oblodai\Contract\Request;

        %USES%/**
         * Body of `%ROUTE%`.
         */
        final class %CLASS% implements RequestBody
        {
            use NormalizesFields;

        %CTORDOC%    public function __construct(
        %PARAMS%
            ) {
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return self::normalize([
        %FIELDS%
                ]);
            }
        }

        PHP;

    $written = [];
    $missing = [];
    foreach ($routes as $route) {
        $key = $route['method'] . ' ' . $route['path'];
        $schema = $route['request_schema'] ?? REQUEST_OVERRIDES[$key] ?? null;
        if (!is_array($schema) || !isset($schema['properties']) || $schema['properties'] === []) {
            continue;
        }
        $required = array_values(array_diff(
            array_merge($schema['required'] ?? [], REQUIRED_OVERRIDES[$key] ?? []),
            OPTIONAL_OVERRIDES[$key] ?? []
        ));
        $names = array_keys($schema['properties']);
        sort($names);
        usort($names, static fn (string $a, string $b): int => (int) !in_array($a, $required, true)
            <=> (int) !in_array($b, $required, true));

        $imports = [];
        $params = [];
        $fields = [];
        $ctorDoc = [];
        foreach ($names as $name) {
            $property = $schema['properties'][$name];
            $isRequired = in_array($name, $required, true);
            $type = phpTypeOf($property, $key, $name, $imports);
            $docType = docTypeOf($property);
            if ($docType !== null) {
                $ctorDoc[] = sprintf('     * @param %s%s $%s', $docType, $isRequired ? '' : '|null', $name);
            }
            $doc = fieldDoc($key, $name, $property, $descriptions, $missing);
            if ($doc !== []) {
                $params[] = count($doc) === 1
                    ? sprintf('        /** %s */', $doc[0])
                    : "        /**\n" . implode("\n", array_map(static fn (string $l): string => '         * ' . $l, $doc)) . "\n         */";
            }
            $declared = $isRequired
                ? $type
                : (str_contains($type, '|') ? $type . '|null' : '?' . $type);
            $params[] = sprintf(
                '        public readonly %s $%s%s,',
                $declared,
                $name,
                $isRequired ? '' : ' = null'
            );
            $fields[] = sprintf("            '%s' => \$this->%s,", $name, $name);
        }

        $uses = '';
        if ($imports !== []) {
            $importNames = array_keys($imports);
            sort($importNames);
            $uses = implode('', array_map(
                static fn (string $n): string => 'use Oblodai\\Contract\\Enum\\' . $n . ";\n",
                $importNames
            )) . "\n";
        }

        $written[] = writeGenerated($outDir . '/Request/' . requestClassName($route) . '.php', strtr($template, [
            '%HEADER%' => $header,
            '%USES%' => $uses,
            '%ROUTE%' => $key,
            '%CLASS%' => requestClassName($route),
            '%CTORDOC%' => $ctorDoc === [] ? '' : "    /**\n" . implode("\n", $ctorDoc) . "\n     */\n",
            '%PARAMS%' => implode("\n", $params),
            '%FIELDS%' => implode("\n", $fields),
        ]));
    }

    if ($missing !== []) {
        fwrite(STDERR, sprintf(
            "codegen: %d request fields lack an English description in contract/descriptions.en.json:\n  %s\n",
            count($missing),
            implode("\n  ", $missing)
        ));
    }

    return $written;
}
