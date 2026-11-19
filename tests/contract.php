<?php

declare(strict_types=1);

/**
 * Contract test: run every recorded scenario against a target (normally
 * the simulator) and compare it with the fixture from a real DIMAG.
 *
 *   php tests/contract.php [--version=3.5.0] [--root=sim-2] [--keep=dir]
 *
 * Target from DIMAG_* variables (README). Exit 1 if any step differs
 * beyond the scenario's known_differences.
 */

require_once __DIR__ . '/../recorder/lib/LocalDrop.php';
require_once __DIR__ . '/../recorder/lib/Target.php';
require_once __DIR__ . '/../recorder/lib/Runner.php';
require_once __DIR__ . '/../recorder/lib/Normalizer.php';
require_once __DIR__ . '/../recorder/lib/Compare.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

use DimagSim\Recorder\{Compare, Normalizer, Runner, Target};

$opts = getopt('', ['version::', 'root::', 'keep::']);
$version = $opts['version'] ?? getenv('DIMAG_SIM_VERSION') ?: '3.5.0';
$root = $opts['root'] ?? getenv('DIMAG_TEST_ROOT') ?: 'sim-2';

$failed = 0;
foreach (glob(__DIR__ . "/../fixtures/$version/*.json") as $fixtureFile) {
    $fixture = json_decode((string) file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);
    $scenario = require __DIR__ . '/../recorder/scenarios/' . $fixture['scenario'] . '.php';
    $run = 'c' . date('ymdHis') . random_int(10, 99);
    fwrite(STDERR, "\n{$fixture['scenario']} (DIMAG {$fixture['dimag_version']}, recorded {$fixture['recorded_on']})\n");

    $target = Target::fromEnv();
    $vars = ['run' => $run, 'root' => $root, 'start' => date('Y-m-d H:i:s', time() - 60),
        'soap_base' => substr($target->soapUrl, 0, strrpos($target->soapUrl, '/') + 1)];
    $raw = [
        'scenario' => $scenario['name'], 'recorded_at' => date('c'), 'run' => $run, 'root' => $root,
        'soap_path' => parse_url($target->soapUrl, PHP_URL_PATH),
        'steps' => (new Runner($target, $vars))->run($scenario),
    ];
    $actual = (new Normalizer())->fixture($raw);
    if (!empty($opts['keep'])) {
        file_put_contents(rtrim($opts['keep'], '/') . "/{$scenario['name']}.json",
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $known = ($scenario['known_differences'] ?? []) + ($fixture['known_differences'] ?? []);
    $diffs = Compare::diff($fixture, $actual, $known);
    foreach ($diffs as $id => $lines) {
        echo "✗ {$scenario['name']}/$id\n  " . implode("\n  ", $lines) . "\n";
    }
    $steps = count($fixture['steps']);
    echo sprintf("%s %s: %d of %d steps match%s\n", $diffs ? '✗' : '✓', $scenario['name'], $steps - count($diffs), $steps,
        $known ? ' (' . count($known) . ' known differences)' : '');
    $failed += count($diffs);
}
exit($failed ? 1 : 0);
