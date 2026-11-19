<?php

declare(strict_types=1);

/**
 * Tests of what only the simulator has: seeding, error switches, version
 * endpoints, accounts. Run against a simulator with its control API on:
 *
 *   php tests/sim.php
 *
 * Target from DIMAG_* variables, as for the contract test.
 */

require_once __DIR__ . '/../recorder/lib/LocalDrop.php';
require_once __DIR__ . '/../recorder/lib/Target.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

use DimagSim\Recorder\Target;

$t = Target::fromEnv();
$base = substr($t->soapUrl, 0, strpos($t->soapUrl, '/soap/'));
$failures = 0;
$count = 0;

function control(string $base, string $action, ?array $body = null): array
{
    $ctx = stream_context_create(['http' => ['method' => $body === null && $action === 'state' ? 'GET' : 'POST',
        'header' => 'Content-Type: application/json', 'content' => json_encode($body ?? new stdClass()), 'ignore_errors' => true]]);
    return json_decode((string) file_get_contents("$base/_sim/$action", false, $ctx), true) ?? [];
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    echo ($ok ? '✓ ' : '✗ ') . $name . ($ok || $detail === '' ? '' : "\n    $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

$r = fn (array $call) => $call['result'] ?? [];

control($base, 'reset');
control($base, 'seed', [
    ['aid' => 'test-o', 'typ' => 'O', 'pid' => 'sim-2', 'titel' => 'Delivery', 'fields' => ['entstehungs-zeitraum' => '2026']],
    ['aid' => 'test-r1', 'typ' => 'R', 'pid' => 'test-o', 'titel' => 'first'],
    ['aid' => 'test-r2', 'typ' => 'R', 'pid' => 'test-o', 'titel' => 'second'],
    ['aid' => 'test-f', 'typ' => 'F', 'pid' => 'test-r2', 'titel' => 'f.txt', 'content' => "hello\n"],
    ['aid' => 'other-s', 'typ' => 'S', 'pid' => 'sim-1', 'titel' => 'not for inge'],
]);

// seeded objects
$kv = $r($t->soap('getAIDforKeyValue', ['aid', 'test-%', 'Any']));
check('seeded objects are found', ($kv['aIDList'] ?? '') === 'test-f;test-o;test-r1;test-r2', json_encode($kv));
$kv = $r($t->soap('getAIDforKeyValue', ['md5', md5("hello\n"), 'File']));
check('md5 of seeded content', ($kv['aIDList'] ?? '') === 'test-f', json_encode($kv));

// visibility
$e = $r($t->soap('exportDoc', ['other-s', 'all', false, true, false]));
check('object outside the account\'s root is not found', ($e['msg'] ?? '') === 'Exception: Failed to find other-s', json_encode($e));
$e = $r($t->soap('exportDoc', ['other-s', 'all', false, true, false], 'admin'));
check('admin sees it', (int) ($e['status'] ?? 0) === 200, json_encode($e));

// RepOptions
$e = $r($t->soap('exportDoc', ['test-o', 'first', false, true, false]));
check('RepOptions=first exports O and its first R', str_contains($e['msg'] ?? '', 'Number Objects exported: 2'), json_encode($e));
$e = $r($t->soap('exportDoc', ['test-o', 'last', false, true, false]));
check('RepOptions=last exports O, last R and its F', str_contains($e['msg'] ?? '', 'Number Objects exported: 3'), json_encode($e));
$e = $r($t->soap('exportDoc', ['test-o', 'all', false, true, true], 'admin'));
check('LogFile=true shows protocol objects to an admin', str_contains($e['msg'] ?? '', 'Number Objects exported: 7'), json_encode($e));

// export with data writes the primary file
$e = $r($t->soap('exportDoc', ['test-f', 'all', false, false, false]));
$dir = dirname((string) ($e['ControlFile'] ?? ''));
$data = $t->sftp()->get($t->sftpPath($dir . '/test-f'));
check('export with data writes {aid}', $data === "hello\n", var_export($data, true));

// error switches
control($base, 'faults', ['deny' => ['test-o']]);
$e = $r($t->soap('exportDoc', ['test-o', 'all', false, true, false]));
check('deny: export fails with "access rights"', str_contains($e['msg'] ?? '', 'access rights'), json_encode($e));

control($base, 'faults', ['failExport' => ['test-o']]);
$e = $r($t->soap('exportDoc', ['test-o', 'all', false, true, false]));
check('failExport: status 500', (int) ($e['status'] ?? 0) === 500, json_encode($e));

control($base, 'faults', ['corrupt' => ['test-f']]);
$e = $r($t->soap('exportDoc', ['test-f', 'all', false, false, false]));
$data = $t->sftp()->get($t->sftpPath(dirname((string) $e['ControlFile']) . '/test-f'));
check('corrupt: exported data no longer matches its md5', md5((string) $data) !== md5("hello\n"));

control($base, 'faults', ['failMoveAt' => 2]);
$m1 = $t->soap('moveObject', ['test-f', 'test-r1']);
$m2 = $t->soap('moveObject', ['test-f', 'test-r2']);
check('failMoveAt=2: first move works', (int) ($r($m1)['status'] ?? 0) === 200, json_encode($m1['result']));
check('failMoveAt=2: second move is a SOAP fault', ($m2['fault']['faultstring'] ?? '') === 'Connection lost during moveObject', json_encode($m2['fault']));

control($base, 'faults', ['down' => true]);
$s = $t->soap('getStatus', []);
check('down: HTTP 503, client throws', $s['fault'] !== null && $s['http_status'] === 503, json_encode($s['fault']) . ' ' . $s['http_status']);
control($base, 'faults', []);

// accounts
$bad = new Target($t->soapUrl, ['user' => ['inge', 'wrong']], []);
$x = $r($bad->soap('getObjectsChangedSince', ['2000-01-01 00:00:00']));
check('wrong password: status 401', (int) ($x['status'] ?? 0) === 401, json_encode($x));

// status 40 (admin): primary data gone, metadata stays
$t->soap('changeStatusCode', ['test-f', 30]);
$x = $r($t->soap('changeStatusCode', ['test-f', 40], 'admin'));
$e = $r($t->soap('exportDoc', ['test-f', 'all', false, true, false]));
check('status 40 keeps the metadata', (int) ($x['status'] ?? 0) === 200 && (int) ($e['status'] ?? 0) === 200, json_encode([$x, $e]));

// version endpoints
$old = $r($t->soap('getStatus', [], 'user', str_replace('webservice_3_5_0', 'webservice_3_2_0', $t->soapUrl)));
check('3.5.0 still answers on webservice_3_2_0', (int) ($old['status'] ?? 0) === 200, json_encode($old));
$new = $t->soap('getStatus', [], 'user', str_replace('webservice_3_5_0', 'webservice_3_6_0', $t->soapUrl));
check('3.5.0 has no webservice_3_6_0 (404)', $new['http_status'] === 404, (string) $new['http_status']);

// unknown operation
$u = $t->soap('recharacterizeAID', ['test-f']);
check('unknown operation is a SOAP fault', str_contains($u['fault']['faultstring'] ?? '', 'not present'), json_encode($u['fault']));

control($base, 'reset');
echo "\n" . ($count - $failures) . " of $count checks passed\n";
exit($failures ? 1 : 0);
