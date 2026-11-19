<?php

declare(strict_types=1);

/**
 * Run scenarios against a DIMAG (real or simulated) and write the raw
 * exchange as JSON. Credentials are masked; the raw file still holds real
 * AIDs, titles and hostnames and must not be committed: run normalize.php
 * on it to get a publishable fixture.
 *
 *   php recorder/record.php --scenario=recorder/scenarios/read-only.php --out=raw.json \
 *       [--root=AID] [--run=TAG] [--from-inge=/var/www/inge --archive=slug]
 *
 * Without --from-inge the target is read from DIMAG_* environment variables
 * (see README). With --from-inge the archive's SOAP/SFTP settings and the
 * admin account are taken from an Inge installation, in-process only.
 */

$opts = getopt('', ['scenario:', 'out:', 'root::', 'run::', 'from-inge::', 'archive::', 'autoload::']);
foreach (['scenario', 'out'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "missing --$required\n");
        exit(2);
    }
}

if (!empty($opts['from-inge'])) {
    $inge = rtrim($opts['from-inge'], '/');
    require $inge . '/vendor/autoload.php';
    $app = require $inge . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $archive = App\Models\Archive::where('slug', $opts['archive'] ?? '')->firstOrFail();
    $set = fn (string $k, $v) => putenv($k . '=' . (string) $v);
    $set('DIMAG_SOAP_URL', $archive->soap_url);
    $set('DIMAG_USER', $archive->soap_username);
    $set('DIMAG_PASSWORD', $archive->soap_password);
    $set('DIMAG_ADMIN_USER', config('soap.username'));
    $set('DIMAG_ADMIN_PASSWORD', config('soap.password'));
    $set('DIMAG_SFTP_HOST', $archive->sftp_address);
    $set('DIMAG_SFTP_PORT', $archive->sftp_port ?: 22);
    $set('DIMAG_SFTP_USER', $archive->sftp_username);
    $set('DIMAG_SFTP_PASSWORD', $archive->sftp_password);
    $set('DIMAG_SFTP_ROOT', $archive->sftp_path);
    if (config('soap.ca_file')) {
        $set('DIMAG_CA_FILE', config('soap.ca_file'));
    }
} elseif (is_file($autoload = $opts['autoload'] ?? __DIR__ . '/../vendor/autoload.php')) {
    require $autoload;
}

require_once __DIR__ . '/lib/LocalDrop.php';
require_once __DIR__ . '/lib/Target.php';
require_once __DIR__ . '/lib/Runner.php';

$scenario = require $opts['scenario'];
$run = $opts['run'] ?? date('ymdHis');
$vars = ['run' => $run, 'root' => $opts['root'] ?? '', 'start' => date('Y-m-d H:i:s', time() - 60)];

if (!empty($scenario['writes']) && $vars['root'] === '') {
    fwrite(STDERR, "scenario {$scenario['name']} writes to DIMAG and needs --root\n");
    exit(2);
}

$target = DimagSim\Recorder\Target::fromEnv();
$vars['soap_base'] = substr($target->soapUrl, 0, strrpos($target->soapUrl, '/') + 1);
fwrite(STDERR, "scenario {$scenario['name']} (run $run)\n");
$records = (new DimagSim\Recorder\Runner($target, $vars))->run($scenario);

$out = [
    'scenario' => $scenario['name'],
    'recorded_at' => date('c'),
    'run' => $run,
    'root' => $vars['root'],
    'sftp_root' => $target->sftpRoot(),
    'soap_path' => parse_url($target->soapUrl, PHP_URL_PATH),
    'known_differences' => $scenario['known_differences'] ?? [],
    'steps' => $records,
];
file_put_contents($opts['out'], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDERR, "written {$opts['out']}\n");
