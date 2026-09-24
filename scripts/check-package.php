<?php

// The composer archive carries the library and its documents, and nothing of the repository's own
// machinery: `php scripts/check-package.php <archive.zip>` (needs ext-zip; run by `make package`).
declare(strict_types=1);

$archive = $argv[1] ?? '';
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    fwrite(STDERR, "check-package: cannot open {$archive}\n");
    exit(1);
}
$names = [];
for ($i = 0; $i < $zip->numFiles; ++$i) {
    $names[] = (string) $zip->getNameIndex($i);
}
$problems = [];
foreach (['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'MIGRATION-2.0.md', 'src/Oblodai.php', 'src/Core/Transport.php', 'src/Generated/Routes.php'] as $must) {
    if (!in_array($must, $names, true)) {
        $problems[] = "missing {$must}";
    }
}
foreach ($names as $name) {
    if (preg_match('#^(tests|examples|scripts|\.github|\.cache|\.phpunit\.cache|vendor)/|^(Makefile|names\.lock|names\.2\.0\.txt|composer\.lock|phpstan\.neon|phpunit\.xml)$#', $name) === 1) {
        $problems[] = "ships {$name}";
    }
}
if ($problems !== []) {
    fwrite(STDERR, 'check-package: ' . implode('; ', array_slice($problems, 0, 10)) . "\n");
    exit(1);
}
printf("package: %d files, the library and its documents only\n", count($names));
