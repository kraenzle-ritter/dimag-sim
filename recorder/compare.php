<?php

declare(strict_types=1);

/**
 * Compare a fixture with a normalized run; exit 1 on differences.
 *
 *   php recorder/compare.php fixtures/3.5.0/lifecycle.json run.json
 */
require_once __DIR__ . '/lib/Compare.php';

[$expected, $actual] = array_map(fn ($f) => json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR), array_slice($argv, 1, 2));
$diffs = DimagSim\Recorder\Compare::diff($expected, $actual, $expected['known_differences'] ?? []);
foreach ($diffs as $id => $lines) {
    echo "✗ $id\n  " . implode("\n  ", $lines) . "\n";
}
echo count($diffs) ? count($diffs) . " step(s) differ\n" : "all steps match\n";
exit(count($diffs) ? 1 : 0);
