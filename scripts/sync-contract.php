<?php

declare(strict_types=1);

/*
 * The contract snapshot CI tests against, without the private backend checkout.
 *
 * contract/conformance/*.json is the backend's shared conformance suite (tools/sdkgen/conformance),
 * and contract/signing.json the spec's `x-oblodai-signing` block the suites point into (request and
 * webhook vectors, header names). With a backend ($OBLODAI_BACKEND, else ../oblodai-backend):
 *   php scripts/sync-contract.php          refresh the snapshot   (make contract)
 *   php scripts/sync-contract.php --check  fail when it is stale  (part of make ci)
 */

$root = dirname(__DIR__);
$out = $root . '/contract';
$env = getenv('OBLODAI_BACKEND');
$backend = is_string($env) && $env !== '' ? rtrim($env, '/') : dirname($root) . '/oblodai-backend';
$suiteDir = $backend . '/tools/sdkgen/conformance';
$spec = $backend . '/services/core/api/openapi.json';
$check = in_array('--check', $argv, true);

if (!is_dir($suiteDir) || !is_file($spec)) {
    fwrite(STDERR, sprintf("sync-contract: no conformance suite at %s (set OBLODAI_BACKEND)\n", $suiteDir));
    exit(1);
}

$want = [];
$files = glob($suiteDir . '/*.json') ?: [];
sort($files);
foreach ($files as $file) {
    $want['conformance/' . basename($file)] = (string) file_get_contents($file);
}
$decoded = json_decode((string) file_get_contents($spec), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($decoded) || !isset($decoded['x-oblodai-signing'])) {
    fwrite(STDERR, "sync-contract: the spec has no x-oblodai-signing\n");
    exit(1);
}
$want['signing.json'] = json_encode(
    ['x-oblodai-signing' => $decoded['x-oblodai-signing']],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
) . "\n";

$have = [];
foreach (glob($out . '/conformance/*') ?: [] as $file) {
    $have['conformance/' . basename($file)] = (string) file_get_contents($file);
}
if (is_file($out . '/signing.json')) {
    $have['signing.json'] = (string) file_get_contents($out . '/signing.json');
}

if ($check) {
    $stale = [];
    foreach (array_unique(array_merge(array_keys($want), array_keys($have))) as $name) {
        if (($want[$name] ?? null) !== ($have[$name] ?? null)) {
            $stale[] = $name;
        }
    }
    if ($stale !== []) {
        sort($stale);
        fwrite(STDERR, sprintf("sync-contract: contract/ is stale (%s); run `make contract`\n", implode(', ', $stale)));
        exit(1);
    }
    echo "contract snapshot matches {$backend}\n";
    exit(0);
}

foreach (glob($out . '/conformance/*') ?: [] as $file) {
    unlink($file);
}
@mkdir($out . '/conformance', 0o777, true);
foreach ($want as $name => $text) {
    file_put_contents($out . '/' . $name, $text);
}
echo "contract snapshot refreshed from {$backend}\n";
