<?php

/**
 * Drives Inge's own SoapController and SftpController against the simulator
 * (docker compose: SOAP localhost:8080, SFTP localhost:2222). Inge is
 * booted read-only; nothing is written to its database.
 *
 *   php examples/inge-smoke.php /path/to/inge
 */

$inge = rtrim($argv[1] ?? '', '/');
putenv('LOG_CHANNEL=stderr');
putenv('LOG_LEVEL=error');
require $inge . '/vendor/autoload.php';
$app = require $inge . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['soap.username' => 'admin', 'soap.password' => 'admin', 'logging.default' => 'stderr']);

use App\Http\Controllers\SftpController;
use App\Http\Controllers\SoapController;
use App\Helpers\ExportedControlFile;
use App\Helpers\LoadXml;

$archive = new App\Models\Archive();
$archive->forceFill([
    'slug' => 'demo', 'parent_aid' => 'sim-2',
    'soap_url' => getenv('DIMAG_SOAP_URL') ?: 'http://localhost:8080/soap/webservice_3_5_0.php',
    'soap_username' => 'inge', 'soap_password' => 'inge',
    'sftp_address' => getenv('DIMAG_SFTP_HOST') ?: 'localhost', 'sftp_port' => (int) (getenv('DIMAG_SFTP_PORT') ?: 2222),
    'sftp_username' => 'inge', 'sftp_password' => 'inge', 'sftp_path' => 'Import',
]);
$soap = new SoapController($archive);
$sftp = new SftpController($archive);
$ok = 0;
$fail = 0;
$check = function (string $what, bool $cond, $detail = null) use (&$ok, &$fail) {
    echo ($cond ? '✓ ' : '✗ ') . $what . ($cond || $detail === null ? '' : ' — ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $cond ? $ok++ : $fail++;
};

$run = date('His');
$content = "Inge smoke test $run\n";
$md5 = md5($content);
$o = ['aid' => "demo-o-$run", 'typ' => 'O', 'pid' => 'sim-2', 'titel' => 'Delivery', 'entstehungs-zeitraum' => '2026'];
$r = ['aid' => "demo-o-$run-r1", 'typ' => 'R', 'pid' => "demo-o-$run", 'titel' => 'Dateien'];
$f = ['aid' => "demo-media-$run", 'typ' => 'F', 'pid' => "demo-o-$run-r1", 'dateiname' => 'a.txt',
    'sftp-dateiname' => "demo/demo-media-$run/a.txt", 'titel' => 'a.txt', 'md5' => $md5];

$check('status: SOAP', $soap->status(), $soap->messageBag->toArray());
$check('status: SFTP', $sftp->status(), $sftp->messageBag->toArray());

// ingest as IngestController does: file + control file on the SFTP, importDoc, finalize bottom up
$disk = (fn () => $this->getDisk())->call($sftp);
$disk->put("/Import/demo/demo-media-$run/a.txt", $content);
$sftp->verifyChecksum("/Import/demo/demo-media-$run", 'a.txt', $md5);
$check('verifyChecksum on the SFTP', true);
$sftp->putControlFile("demo/demo-media-$run/load-xml.xml", LoadXml::controlFile('sim-2', [$o, $r, $f]));
$res = $soap->importDoc("demo/demo-media-$run/load-xml.xml", $f['aid']);
$check('importDoc', (int) $res['status'] === 200, $res);
foreach ([$f['aid'], $r['aid'], $o['aid']] as $aid) {
    $res = $soap->finalize($aid);
    $check("finalize $aid", (int) $res['status'] === 200, $res);
}

// inventory
$aids = $soap->listAids('1970-01-01 00:00:00');
$check('listAids contains the new objects', !array_diff([$o['aid'], $r['aid'], $f['aid']], $aids), $aids);
$exp = $soap->exportMetadata($o['aid']);
$objects = ExportedControlFile::parse($sftp->getControlFile($exp['ControlFile']));
$byAid = array_column($objects, null, 'aid');
$check('exportMetadata + ExportedControlFile::parse', count($objects) === 3 && $byAid[$f['aid']]['md5'] === $md5
    && $byAid[$f['aid']]['status'] === 20 && $byAid[$f['aid']]['pid'] === $r['aid'], $objects);
$check('deleteExport', $sftp->deleteExport($exp['ControlFile']));

// file retrieval as FileController does
$doc = $soap->exportDoc($f['aid']);
$check('exportDoc', (int) $doc['status'] === 200 && str_contains($doc['msg'], 'Number Objects exported: 1'), $doc);
$got = $sftp->getFile(dirname($doc['ControlFile']) . '/' . $f['aid'], 'txt');
$check('getFile returns the primary data', $got === $content, $got);
$st = $soap->getStatusForAid($f['aid']);
$check('getStatusForAid: exists, status unknown (DIMAG gives no statusCode)', $st['exists'] === true && !empty($st['statusUnknown']), $st);

// restructure: move + md5 of a sample
$r2 = ['aid' => "demo-o-$run-r2", 'typ' => 'R', 'pid' => $o['aid'], 'titel' => 'Dateien 2'];
$sftp->putControlFile("demo/restructure-$run/load-xml.xml", LoadXml::controlFile('sim-2', [$r2]));
$check('importDoc of an R without files', (int) $soap->importDoc("demo/restructure-$run/load-xml.xml", $r2['aid'])['status'] === 200);
$soap->moveObject($f['aid'], $r2['aid']);
$exp = $soap->exportWithData($f['aid']);
$check('exportWithData + exportedFileMd5 after move', $sftp->exportedFileMd5($exp['ControlFile'], $f['aid']) === $md5);
$sftp->deleteExport($exp['ControlFile']);

// lookups
$kv = $soap->getAidForKeyValue('md5', $md5);
$check('getAidForKeyValue(md5)', ($kv['aIDList'] ?? '') === $f['aid'], $kv);
$check('getAidsWithPrefix finds nothing, as on the real DIMAG (* is no wildcard)', $soap->getAidsWithPrefix('demo-media') === null
    || (int) ($soap->getAidsWithPrefix('demo-media')['hits'] ?? 0) === 0);

// errors Inge handles
try {
    $soap->exportMetadata('demo-missing');
    $check('exportMetadata of a missing AID throws', false);
} catch (App\Exceptions\DimagRequestFailed $e) {
    $check('exportMetadata of a missing AID throws DimagRequestFailed', !$e instanceof App\Exceptions\DimagAccessDenied, $e->getMessage());
}
$disk->put("/Import/demo/bad-$run/a.txt", 'other content');
$sftp->putControlFile("demo/bad-$run/load-xml.xml", LoadXml::controlFile('sim-2', [['md5' => $md5, 'sftp-dateiname' => "demo/bad-$run/a.txt", 'aid' => "demo-media-bad-$run"] + $f]));
$res = $soap->importDoc("demo/bad-$run/load-xml.xml", "demo-media-bad-$run");
$check('md5 mismatch: importDoc status 400', (int) $res['status'] === 400 && str_contains($res['msg'], 'md5 mismatch'), $res);
$sftp->deleteDir("/Import/demo/bad-$run");

// delete (status 30 as Inge's user, 50 as admin)
$check('delete() removes the F', $soap->delete($f['aid']), $soap->messageBag->toArray());
$check('delete() of the O with its R', $soap->delete($o['aid']), $soap->messageBag->toArray());
$check('nothing left', !array_intersect([$o['aid'], $r['aid'], $r2['aid'], $f['aid']], $soap->listAids('1970-01-01 00:00:00')));

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
