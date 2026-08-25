<?php

declare(strict_types=1);

/**
 * CI gate: the committed src/Contract must be exactly what scripts/codegen.php produces from
 * contract/contract.json. Run: composer check-drift.
 */

$root = dirname(__DIR__);
$dir = $root . '/src/Contract';

/** @return array<string, string> path (relative) => sha256 */
$snapshot = static function (string $dir): array {
    $out = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'GENERATED FILE')) {
                $out[$file->getPathname()] = hash('sha256', $contents);
            }
        }
    }
    ksort($out);

    return $out;
};

$before = $snapshot($dir);

$exitCode = 0;
passthru(PHP_BINARY . ' ' . escapeshellarg($root . '/scripts/codegen.php'), $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "check-drift: codegen failed\n");
    exit(1);
}

$after = $snapshot($dir);

$drifted = [];
foreach ($after as $path => $hash) {
    if (!isset($before[$path])) {
        $drifted[] = 'new: ' . $path;
    } elseif ($before[$path] !== $hash) {
        $drifted[] = 'changed: ' . $path;
    }
}
foreach ($before as $path => $_hash) {
    if (!isset($after[$path])) {
        $drifted[] = 'removed: ' . $path;
    }
}

if ($drifted !== []) {
    fwrite(STDERR, sprintf(
        'contract drift — the generated files differ from contract/contract.json; commit the '
            . "regenerated output:\n  %s\n",
        implode("\n  ", $drifted)
    ));
    exit(1);
}

printf("check-drift: %d generated files in src/Contract are in sync\n", count($after));
