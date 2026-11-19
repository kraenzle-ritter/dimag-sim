<?php

declare(strict_types=1);

/**
 * Raw recording -> publishable fixture.
 *
 *   php recorder/normalize.php raw.json > fixtures/3.5.0/lifecycle.json [--scrub=word,word]
 */
require_once __DIR__ . '/lib/Normalizer.php';

$opts = getopt('', ['scrub::'], $rest);
$file = $argv[$rest] ?? null;
if (!$file) {
    fwrite(STDERR, "usage: normalize.php raw.json [--scrub=a,b]\n");
    exit(2);
}
$scrub = array_filter(array_map('trim', explode(',', (string) ($opts['scrub'] ?? getenv('DIMAG_SCRUB') ?: ''))));
$raw = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
echo json_encode((new DimagSim\Recorder\Normalizer($scrub))->fixture($raw), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
