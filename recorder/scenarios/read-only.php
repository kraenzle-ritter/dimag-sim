<?php

/*
 * Calls that change nothing in DIMAG. None of them names an existing
 * object, so the recording holds no archive content.
 */
return [
    'name' => 'read-only',
    'writes' => false,
    'steps' => [
        ['id' => 'status', 'soap' => 'getStatus'],
        ['id' => 'prefix_list', 'soap' => 'getPrefixList'],
        ['id' => 'changed_future', 'soap' => 'getObjectsChangedSince', 'args' => ['2099-01-01 00:00:00'],
            'note' => 'no object changed after this date'],
        ['id' => 'changed_date_only', 'soap' => 'getObjectsChangedSince', 'args' => ['2099-01-01'],
            'note' => 'date without time'],
        ['id' => 'changed_garbage', 'soap' => 'getObjectsChangedSince', 'args' => ['not a date']],
        ['id' => 'kv_no_hit', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', 'dimagsim-none-{run}%', 'File']],
        ['id' => 'kv_no_hit_star', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', 'dimagsim-none-{run}*', 'File'],
            'note' => 'Inge getAidsWithPrefix uses * as wildcard'],
        ['id' => 'kv_md5_no_hit', 'soap' => 'getAIDforKeyValue', 'args' => ['md5', '00000000000000000000000000000000', 'File'],
            'note' => 'Inge ChecksumController'],
        ['id' => 'kv_bad_type', 'soap' => 'getAIDforKeyValue', 'args' => ['aid', 'dimagsim-none-{run}%', 'Nonsense']],
        ['id' => 'export_missing_meta', 'soap' => 'exportDoc', 'args' => ['dimagsim-none-{run}', 'all', false, true, false],
            'note' => 'Inge exportMetadata on an unknown AID'],
        ['id' => 'export_missing_doc', 'soap' => 'exportDoc', 'args' => ['dimagsim-none-{run}', 'last', false, false, true],
            'note' => 'Inge exportDoc / getStatusForAid on an unknown AID'],
        ['id' => 'status_360', 'soap' => 'getStatus', 'location' => '{soap_base}webservice_3_6_0.php',
            'note' => 'is a 3.6.0 endpoint offered?'],
        ['id' => 'status_admin', 'soap' => 'getStatus', 'account' => 'admin', 'needs' => 'admin'],
        ['id' => 'changed_future_admin', 'soap' => 'getObjectsChangedSince', 'args' => ['2099-01-01 00:00:00'],
            'account' => 'admin', 'needs' => 'admin'],
    ],
];
