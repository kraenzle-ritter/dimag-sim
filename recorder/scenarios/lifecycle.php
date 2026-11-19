<?php

/*
 * The life of a small tree, as Inge drives it: ingest S/O/R/F via control
 * file, read it back, finalize, move, export with data, provoke the errors
 * Inge has to handle, and remove everything again (status 30, then 50 by
 * an admin).
 *
 * Writes to DIMAG. Every object it creates carries the AID prefix
 * dimagsim-{run}- and sits below --root; the last steps delete them.
 * DIMAG keeps one protocol object (P) per created or changed object.
 */

$x = fn (string $s) => htmlspecialchars($s, ENT_XML1);

/** control file: rootid + flat list of verz-obj with explicit pid, as Inge's LoadXml::controlFile writes it */
$control = function (?string $rootid, array $objects) use ($x): string {
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<verzeichnungseinheit>\n";
    if ($rootid !== null) {
        $xml .= "  <rootid>{$x($rootid)}</rootid>\n";
    }
    foreach ($objects as $o) {
        $xml .= "  <verz-obj>\n";
        foreach ($o as $k => $v) {
            $xml .= "    <$k>{$x((string) $v)}</$k>\n";
        }
        $xml .= "  </verz-obj>\n";
    }
    return $xml . "</verzeichnungseinheit>\n";
};

$p = 'dimagsim-{run}';          // AID prefix of this run
$dir = 'dimagsim-{run}';        // SFTP working directory below sftp_root
$content = str_repeat("dimag-sim synthetic test file\n", 35);  // 1050 bytes
$md5 = md5($content);
$content2 = str_repeat("dimag-sim second file\n", 10);
$md52 = md5($content2);

$s = ['aid' => "$p-s", 'typ' => 'S', 'titel' => 'dimag-sim structure', 'signatur-anteil' => 'dimagsim'];
$o = ['pid' => "$p-s", 'aid' => "$p-o", 'typ' => 'O', 'titel' => 'dimag-sim delivery', 'entstehungs-zeitraum' => '2026'];
$r1 = ['pid' => "$p-o", 'aid' => "$p-r1", 'typ' => 'R', 'titel' => 'Dateien'];
$r2 = ['pid' => "$p-o", 'aid' => "$p-r2", 'typ' => 'R', 'titel' => 'Dateien 2'];
$f1 = ['pid' => "$p-r1", 'aid' => "$p-f1", 'dateiname' => 'test.txt', 'sftp-dateiname' => "$dir/1/test.txt",
    'titel' => 'test.txt', 'typ' => 'F', 'provenienz' => 'dimagsim', 'uebernahme' => "$p-r1", 'md5' => $md5];

$import = fn (string $id, string $n, ?string $rootid, array $objects, array $extra = []) => [
    ['id' => "{$id}_put", 'sftp' => 'put', 'path' => "$dir/$n/load-xml.xml", 'content' => $control($rootid, $objects)],
    ['id' => $id, 'soap' => 'importDoc', 'args' => ["$dir/$n/load-xml.xml"]] + $extra,
];

$steps = [
    ['id' => 'status', 'soap' => 'getStatus'],

    // 1 ingest a tree S/O/R/F plus an empty R, the way Inge's delivery structure does
    ['id' => 'file1_put', 'sftp' => 'put', 'path' => "$dir/1/test.txt", 'content' => $content],
    ...$import('import_tree', '1', '{root}', [$s, $o, $r1, $r2, $f1]),
    ['id' => 'import_tree_dir', 'sftp' => 'list', 'path' => "$dir/1", 'note' => 'does DIMAG leave the files on the SFTP?'],

    // 2 same control file again: parents unchanged, F unchanged
    ...$import('import_again', '1b', '{root}', [$s, $o, $r1, $r2, ['sftp-dateiname' => "$dir/1/test.txt"] + $f1]),

    // 3 update a title (same type)
    ...$import('import_update_title', '2', '{root}', [['titel' => 'dimag-sim delivery renamed'] + $o]),

    // 4 errors on import
    ...$import('import_type_change', '3', '{root}', [['typ' => 'S'] + $r2], ['note' => 'type of an existing object cannot change']),
    ['id' => 'file_bad_put', 'sftp' => 'put', 'path' => "$dir/4/bad.txt", 'content' => $content2],
    ...$import('import_md5_mismatch', '4', '{root}', [['aid' => "$p-fbad", 'pid' => "$p-r1", 'dateiname' => 'bad.txt',
        'sftp-dateiname' => "$dir/4/bad.txt", 'titel' => 'bad.txt', 'typ' => 'F', 'md5' => $md5]]),
    ...$import('import_missing_file', '5', '{root}', [['aid' => "$p-fmissing", 'pid' => "$p-r1", 'dateiname' => 'missing.txt',
        'sftp-dateiname' => "$dir/5/missing.txt", 'titel' => 'missing.txt', 'typ' => 'F', 'md5' => $md52]]),
    ...$import('import_o_without_period', '6', '{root}', [['aid' => "$p-o2", 'pid' => "$p-s", 'typ' => 'O', 'titel' => 'no period']],
        ['note' => 'O needs entstehungs-zeitraum']),
    ...$import('import_unknown_pid', '7', '{root}', [['aid' => "$p-s2", 'pid' => "$p-nothere", 'typ' => 'S', 'titel' => 'orphan']]),
    ['id' => 'import_no_control_file', 'soap' => 'importDoc', 'args' => ["$dir/none/load-xml.xml"]],
    ['id' => 'bad_xml_put', 'sftp' => 'put', 'path' => "$dir/8/load-xml.xml", 'content' => "<verzeichnungseinheit><verz-obj>\n"],
    ['id' => 'import_bad_xml', 'soap' => 'importDoc', 'args' => ["$dir/8/load-xml.xml"]],

    // 5 read back
    ['id' => 'changed_since', 'soap' => 'getObjectsChangedSince', 'args' => ['{start}'],
        'note' => 'lists every object of the account changed since the run started, other users\' objects included'],
    ['id' => 'kv_aid', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', "$p-%", 'File']],
    ['id' => 'kv_aid_any', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', "$p-%", 'Any']],
    ['id' => 'kv_aid_star', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', "$p-*", 'File'], 'note' => 'Inge getAidsWithPrefix'],
    ['id' => 'kv_aid_exact', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', "$p-f1", 'File'], 'note' => 'Inge getAidForKeyValue'],
    ['id' => 'kv_md5', 'soap' => 'getAIDforKeyValue', 'args' => ['md5', $md5, 'File'], 'note' => 'Inge ChecksumController'],
    ['id' => 'kv_title_struct', 'soap' => 'getAIDforKeyValue', 'args' => ['titel', 'dimag-sim structure', 'Struct']],

    ['id' => 'export_meta', 'soap' => 'exportDoc', 'args' => ["$p-s", 'all', false, true, false], 'note' => 'Inge exportMetadata'],
    ['id' => 'export_meta_get', 'sftp' => 'get', 'path' => '{export_meta.result.ControlFile}',
        'note' => 'path below sftp_root, as Inge reads it'],
    ['id' => 'export_meta_get_abs', 'sftp' => 'get', 'path' => '{export_meta.result.ControlFile}', 'abs' => true,
        'note' => 'probe: path below the SFTP login directory'],
    ['id' => 'export_meta_list', 'sftp' => 'list', 'path' => '{export_meta.result.ControlFile|dir}'],

    ['id' => 'export_doc', 'soap' => 'exportDoc', 'args' => ["$p-f1", 'last', false, false, true], 'note' => 'Inge exportDoc / getStatusForAid'],
    ['id' => 'export_doc_list', 'sftp' => 'list', 'path' => '{export_doc.result.ControlFile|dir}'],

    ['id' => 'export_data', 'soap' => 'exportDoc', 'args' => ["$p-f1", 'all', false, false, false], 'note' => 'Inge exportWithData'],
    ['id' => 'export_data_list', 'sftp' => 'list', 'path' => '{export_data.result.ControlFile|dir}'],
    ['id' => 'export_data_file', 'sftp' => 'get', 'path' => "{export_data.result.ControlFile|dir}/$p-f1"],
    ['id' => 'export_data_meta', 'sftp' => 'get', 'path' => "{export_data.result.ControlFile|dir}/$p-f1.xml"],

    ['id' => 'export_struct', 'soap' => 'exportDoc', 'args' => ["$p-r1", 'all', true, true, false], 'note' => 'withStruct=true'],
    ['id' => 'export_struct_get', 'sftp' => 'get', 'path' => '{export_struct.result.ControlFile}'],

    // 6 finalize, bottom up as Inge does
    ['id' => 'finalize_f1', 'soap' => 'changeStatusCode', 'args' => ["$p-f1", 20]],
    ['id' => 'finalize_r1', 'soap' => 'changeStatusCode', 'args' => ["$p-r1", 20]],
    ['id' => 'finalize_o', 'soap' => 'changeStatusCode', 'args' => ["$p-o", 20]],
    ['id' => 'finalize_s', 'soap' => 'changeStatusCode', 'args' => ["$p-s", 20]],
    ['id' => 'finalize_again', 'soap' => 'changeStatusCode', 'args' => ["$p-f1", 20]],
    ['id' => 'status_back_to_10', 'soap' => 'changeStatusCode', 'args' => ["$p-f1", 10]],
    ['id' => 'status_invalid', 'soap' => 'changeStatusCode', 'args' => ["$p-f1", 99]],
    ['id' => 'status_unknown_aid', 'soap' => 'changeStatusCode', 'args' => ["$p-nothere", 20]],
    ['id' => 'export_after_finalize', 'soap' => 'exportDoc', 'args' => ["$p-s", 'all', false, true, false]],
    ['id' => 'export_after_finalize_get', 'sftp' => 'get', 'path' => '{export_after_finalize.result.ControlFile}'],

    // 7 a finalized R still takes a new F
    ['id' => 'file2_put', 'sftp' => 'put', 'path' => "$dir/9/second.txt", 'content' => $content2],
    ...$import('import_into_final_r', '9', '{root}', [['aid' => "$p-f2", 'pid' => "$p-r1", 'dateiname' => 'second.txt',
        'sftp-dateiname' => "$dir/9/second.txt", 'titel' => 'second.txt', 'typ' => 'F', 'md5' => $md52]]),

    // 8 move
    ['id' => 'move_f1', 'soap' => 'moveObject', 'args' => ["$p-f1", "$p-r2"]],
    ['id' => 'move_f1_again', 'soap' => 'moveObject', 'args' => ["$p-f1", "$p-r2"], 'note' => 'already there'],
    ['id' => 'move_unknown_parent', 'soap' => 'moveObject', 'args' => ["$p-f1", "$p-nothere"]],
    ['id' => 'move_unknown_object', 'soap' => 'moveObject', 'args' => ["$p-nothere", "$p-r2"]],
    ['id' => 'move_f_below_s', 'soap' => 'moveObject', 'args' => ["$p-f2", "$p-s"], 'note' => 'F only below R'],
    ['id' => 'export_after_move', 'soap' => 'exportDoc', 'args' => ["$p-o", 'all', false, true, false]],
    ['id' => 'export_after_move_get', 'sftp' => 'get', 'path' => '{export_after_move.result.ControlFile}'],

    // 9 delete: status 30 as Inge's user, 50 needs the admin
    ['id' => 'delete_f2_30', 'soap' => 'changeStatusCode', 'args' => ["$p-f2", 30]],
    ['id' => 'delete_f2_50_user', 'soap' => 'changeStatusCode', 'args' => ["$p-f2", 50], 'note' => 'not allowed for the user?'],
    ['id' => 'delete_f2_50_admin', 'soap' => 'changeStatusCode', 'args' => ["$p-f2", 50], 'account' => 'admin', 'needs' => 'admin'],
    ['id' => 'export_deleted', 'soap' => 'exportDoc', 'args' => ["$p-f2", 'all', false, true, false]],
    ['id' => 'kv_after_delete', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', "$p-%", 'File']],

    // 10 clean up: the whole tree, then confirm it is gone
    ['id' => 'cleanup_s_30', 'soap' => 'changeStatusCode', 'args' => ["$p-s", 30]],
    ['id' => 'cleanup_s_50', 'soap' => 'changeStatusCode', 'args' => ["$p-s", 50], 'account' => 'admin', 'needs' => 'admin'],
    ['id' => 'cleanup_extra_30', 'soap' => 'changeStatusCode', 'args' => ["$p-o2", 30], 'note' => 'only if step 4 created it'],
    ['id' => 'cleanup_extra_50', 'soap' => 'changeStatusCode', 'args' => ["$p-o2", 50], 'account' => 'admin', 'needs' => 'admin'],
    ['id' => 'cleanup_fbad_30', 'soap' => 'changeStatusCode', 'args' => ["$p-fbad", 30]],
    ['id' => 'cleanup_fbad_50', 'soap' => 'changeStatusCode', 'args' => ["$p-fbad", 50], 'account' => 'admin', 'needs' => 'admin'],
    ['id' => 'cleanup_check_kv', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', "$p-%", 'Any']],
    ['id' => 'cleanup_check_export', 'soap' => 'exportDoc', 'args' => ["$p-s", 'all', false, true, false]],
    ['id' => 'changed_since_end', 'soap' => 'getObjectsChangedSince', 'args' => ['{start}']],

    // 11 remove what we put on the SFTP and the exports DIMAG wrote
    ['id' => 'cleanup_sftp_work', 'sftp' => 'delete_dir', 'path' => $dir],
    ['id' => 'cleanup_export_meta', 'sftp' => 'delete_dir', 'path' => '{export_meta.result.ControlFile|dir}'],
    ['id' => 'cleanup_export_doc', 'sftp' => 'delete_dir', 'path' => '{export_doc.result.ControlFile|dir}'],
    ['id' => 'cleanup_export_data', 'sftp' => 'delete_dir', 'path' => '{export_data.result.ControlFile|dir}'],
    ['id' => 'cleanup_export_struct', 'sftp' => 'delete_dir', 'path' => '{export_struct.result.ControlFile|dir}'],
    ['id' => 'cleanup_export_final', 'sftp' => 'delete_dir', 'path' => '{export_after_finalize.result.ControlFile|dir}'],
    ['id' => 'cleanup_export_move', 'sftp' => 'delete_dir', 'path' => '{export_after_move.result.ControlFile|dir}'],
];

return [
    'name' => 'lifecycle',
    'writes' => true,
    'steps' => $steps,
    // accepted differences between DIMAG and the simulator, with the reason
    'known_differences' => [
        'export_struct' => 'withStruct: DIMAG also counts ancestors above the account\'s root; how many depends on the installation',
        'export_struct_get' => 'withStruct: DIMAG writes hidden ancestor levels as indentation and extra closing tags; the simulator does not',
    ],
];
