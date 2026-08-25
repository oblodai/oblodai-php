<?php

declare(strict_types=1);

/**
 * Generates src/Contract/{Routes,Enums,Version}.php, src/Contract/Enum/*.php and
 * src/Contract/Request/*.php from contract/contract.json (exported by the core's
 * TestSDKContract_Export) and contract/descriptions.en.json (English field docs).
 *
 * Nothing in the generated files is edited by hand. Run: composer codegen. CI: composer check-drift.
 */

require __DIR__ . '/codegen/emit.php';
require __DIR__ . '/codegen/requests.php';

$root = dirname(__DIR__);
$raw = file_get_contents($root . '/contract/contract.json');
if ($raw === false) {
    fwrite(STDERR, "contract/contract.json is missing\n");
    exit(1);
}
$contract = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$descriptions = json_decode(
    (string) file_get_contents($root . '/contract/descriptions.en.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$outDir = $root . '/src/Contract';
@mkdir($outDir . '/Enum', 0o755, true);
@mkdir($outDir . '/Request', 0o755, true);

$header = '// GENERATED FILE — do not edit. Source: contract/contract.json (core '
    . substr((string) $contract['core_commit'], 0, 12) . ").\n// Regenerate with: composer codegen\n";

// Merchant-facing surface only: infrastructure endpoints are not part of the SDK.
$routes = array_values(array_filter(
    $contract['routes'],
    static fn (array $r): bool => preg_match('#^/(healthz|readyz|docs|openapi\.json|internal)#', $r['path']) !== 1
));
usort($routes, static fn (array $a, array $b): int => $a['path'] === $b['path']
    ? strcmp($a['method'], $b['method'])
    : strcmp($a['path'], $b['path']));

$written = [];
$written[] = emitRoutes($outDir, $header, $routes);
foreach (emitEnums($outDir, $header, $contract) as $file) {
    $written[] = $file;
}
$requestFiles = emitRequests($outDir, $header, $routes, $descriptions);
foreach ($requestFiles as $file) {
    $written[] = $file;
}
$written[] = emitVersion($outDir, $header, $contract, $raw);

// Prune generated files that no longer belong (a route or enum that left the contract).
foreach (['Enum', 'Request'] as $sub) {
    foreach ((array) glob($outDir . '/' . $sub . '/*.php') as $path) {
        if (!in_array($path, $written, true) && str_contains((string) file_get_contents($path), 'GENERATED FILE')) {
            unlink($path);
        }
    }
}

printf(
    "codegen: %d routes, %d request DTOs, %d error codes, contract %s\n",
    count($routes),
    count($requestFiles),
    count($contract['error_codes']),
    substr(hash('sha256', $raw), 0, 12)
);
