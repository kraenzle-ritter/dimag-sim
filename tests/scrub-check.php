<?php

declare(strict_types=1);

/**
 * Checks fixtures for data that must not be published: hostnames other
 * than the public SOAP/XML namespaces, IP addresses, e-mail addresses,
 * absolute server paths, MAC-derived UUIDs. Extra words (names of the
 * archive whose DIMAG was recorded) come from DIMAG_SCRUB_WORDS or a file
 * named in DIMAG_SCRUB_FILE, one word per line; keep that list outside
 * the repository.
 *
 *   php tests/scrub-check.php [files…]
 */

$files = array_slice($argv, 1) ?: glob(__DIR__ . '/../fixtures/*/*.json');
$words = array_filter(array_map('trim', explode(',', (string) getenv('DIMAG_SCRUB_WORDS'))));
if (($list = getenv('DIMAG_SCRUB_FILE')) && is_file($list)) {
    $words = array_merge($words, array_filter(array_map('trim', file($list))));
}
$public = ['schemas.xmlsoap.org', 'www.w3.org', 'dimag.la-bw.de'];

$problems = 0;
foreach ($files as $file) {
    $text = (string) file_get_contents($file);
    $hits = [];
    if (preg_match_all('#https?://([A-Za-z0-9.-]+)#', $text, $m)) {
        foreach (array_unique($m[1]) as $host) {
            if (!in_array(strtolower($host), $public, true)) {
                $hits[] = "host $host";
            }
        }
    }
    if (preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text, $m)) {
        $hits = array_merge($hits, array_map(fn ($ip) => "IP $ip", array_unique($m[0])));
    }
    if (preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/', $text, $m)) {
        $hits = array_merge($hits, array_map(fn ($e) => "e-mail $e", array_unique($m[0])));
    }
    if (preg_match_all('#(?<![\w{}])/(?:data\d*|srv|var|home|opt|mnt)/[\w./-]+#', $text, $m)) {
        $hits = array_merge($hits, array_map(fn ($p) => "path $p", array_unique($m[0])));
    }
    if (preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, $m)) {
        $hits[] = count(array_unique($m[0])) . ' UUID(s)';
    }
    foreach ($words as $w) {
        if (stripos($text, $w) !== false) {
            $hits[] = "word '$w'";
        }
    }
    echo ($hits ? '✗ ' : '✓ ') . basename(dirname($file)) . '/' . basename($file) . ($hits ? ': ' . implode(', ', $hits) : '') . "\n";
    $problems += count($hits);
}
exit($problems ? 1 : 0);
