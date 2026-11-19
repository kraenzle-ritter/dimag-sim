<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/**
 * The behaviour of the DIMAG core module (Kernmodul) as Inge sees it
 * through KM-SOAP. Each operation returns its response fields in the order
 * DIMAG sends them.
 *
 * Messages marked [recorded] were taken verbatim from a real DIMAG 3.5.0;
 * those marked [modelled] could not be provoked there and follow the
 * documentation or a best guess. See docs/behaviour.md.
 */
final class Dimag
{
    public const TYPE_NAMES = [
        'S' => 'Strukturobjekt',
        'O' => 'Informationsobjekt',
        'R' => 'Repräsentation',
        'F' => 'Primärdatenobjekt',
        'P' => 'Protokollobjekt',
    ];

    /** allowed parent types (DIMAG manual 2020, ch. 3.1); null = the repository root */
    private const PARENT_TYPES = [
        'S' => ['S', null],
        'O' => ['S'],
        'R' => ['O'],
        'F' => ['R'],
    ];

    private const KV_TYPES = ['Struct' => 'S', 'DigitalObject' => 'O', 'Representation' => 'R', 'File' => 'F'];

    /** fields an F keeps from a control file; the rest is dropped as DIMAG does (provenienz, uebernahme …) */
    private const FILE_FIELDS = ['aenderungs-datum-datei', 'anzahl-datensaetze', 'anzahl-felder', 'beschreibung-inhalt',
        'datei-format', 'datei-groesse-in-byte', 'dateiname', 'daten-format', 'filepath', 'format-version', 'jhoveformat',
        'md5', 'pronom-mime', 'pronom-signature', 'puid', 'puidformat', 'quirks', 'scandatum', 'titel'];

    /** processing instructions and system fields, never stored as metadata */
    private const NOT_STORED = ['aid', 'pid', 'typ', 'sftp-dateiname', 'tmp-storage-dateiname', 'status-code', 'doVersion',
        'aenderungs-datum', 'erstellungs-datum', 'ersteller', 'geaendert-durch', 'alternate-id', 'metadaten-version',
        'versions-nummer', 'signatur'];

    private const PREFIX = 'processControlAndProtocolXML(): ';

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Files $files,
    ) {
    }

    // ------------------------------------------------------------------
    // operations

    public function getStatus(): array
    {
        $c = $this->config;
        return [
            'status' => 200,
            'msg' => "DIMAG-{$c->version}\t\t[" . date('H:i:s') . "] \n"
                . "\tURL:               \t{$c->publicUrl}\n"
                . "\tArchivName:        \t{$c->archiveName}\n"
                . "\tsftp Path:         \t{$c->sftpDir}\n"
                . "\tImport XSD Version:\t{$c->xsdVersion}\n"
                . "\tDIMAG prefix:      \t{$c->prefix}",
        ];
    }

    /** [recorded] a DIMAG without coupled AFIS */
    public function getPrefixList(): array
    {
        return ['status' => 400, 'msg' => 'Error: fetch of prefixList failed', 'prefixList' => ''];
    }

    public function importDoc(array $user, string $controlFile): array
    {
        $abs = $this->config->sftpDir . '/' . ltrim($controlFile, '/');
        if (!is_file($abs)) {
            // [recorded]
            return ['status' => 404, 'msg' => "No controlfile found under the following path: $abs"];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->load($abs, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement?->nodeName !== 'verzeichnungseinheit') {
            // [recorded] for a truncated file; DIMAG reports the same for every unreadable control file we tried
            return ['status' => 404, 'msg' => "The controlfile '$abs' is invalid: <br/>\n<b>Error 1872</b>: The document has no document element. on line <b>-1</b>\n"];
        }

        $rootid = null;
        $items = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === 'rootid') {
                $rootid = trim($node->textContent) ?: null;
            } elseif ($node instanceof \DOMElement && $node->nodeName === 'verz-obj') {
                $this->collect($node, null, $items);
            }
        }

        $snapshot = $this->store->state;
        $consumed = [];
        try {
            $message = $this->import($user, $rootid, $items, $consumed);
        } catch (ImportError $e) {
            $this->store->state = $snapshot;
            return ['status' => 400, 'msg' => 'Error: ' . self::PREFIX . $e->getMessage()];
        }

        // [recorded] after a successful import the package is gone from the SFTP
        $this->files->consume($abs, $consumed);

        return ['status' => 200, 'msg' => 'Success: ' . self::PREFIX . $message];
    }

    public function exportDoc(array $user, string $aid, string $repOptions, mixed $withStruct, mixed $controlFileOnly, mixed $logFile): array
    {
        $withStruct = self::bool($withStruct);
        $controlFileOnly = self::bool($controlFileOnly);
        $logFile = self::bool($logFile);
        $args = sprintf("arguments: aid=%s; RepOptions=%s; withStruct=%s; ControlFileOnly=%s; LogFile=%s; \n",
            $aid, $repOptions, self::str($withStruct), self::str($controlFileOnly), self::str($logFile));

        $object = $this->visible($user, $aid);
        if (!$object) {
            return ['status' => 500, 'msg' => "Exception: Failed to find $aid", 'ControlFile' => '']; // [recorded]
        }
        if (in_array($aid, $this->store->state['faults']['deny'] ?? [], true)) {
            // [modelled] the wording Inge checks for (DimagAccessDenied)
            return ['status' => 500, 'msg' => "Exception: Not enough access rights to export $aid", 'ControlFile' => ''];
        }
        if (in_array($aid, $this->store->state['faults']['failExport'] ?? [], true)) {
            return ['status' => 500, 'msg' => "Exception: Export of $aid failed", 'ControlFile' => '']; // [modelled]
        }

        $withLog = $logFile && $user['admin'];
        $tree = $this->exportTree($user, $object, $repOptions, $withLog);
        $hidden = 0;
        if ($withStruct) {
            [$tree, $hidden] = $this->wrapAncestors($user, $object, $tree);
        }
        $count = $this->countTree($tree) + $hidden;

        $relDir = $this->files->newExportDir();
        $dir = $this->config->sftpDir . $relDir;
        file_put_contents($dir . '/verz_obj.xml', Xml::exportControlFile($tree, fn ($o) => $this->exportFields($o)));
        if (!$controlFileOnly) {
            $this->walk($tree, function (array $node) use ($dir) {
                $o = $node['object'];
                file_put_contents($dir . '/' . $o['aid'] . '.xml', Xml::metadataFile($this->exportFields($o)));
                if ($o['typ'] === 'F') {
                    $this->files->exportPrimary($o['aid'], $dir . '/' . $o['aid'], $this->store->state['faults']['corrupt'] ?? []);
                }
            });
        }

        return ['status' => 200, 'msg' => $args . "Number Objects exported: $count\n", 'ControlFile' => $relDir . '/verz_obj.xml'];
    }

    public function changeStatusCode(array $user, string $aid, mixed $newStatus): array
    {
        $object = $this->visible($user, $aid);
        if (!$object) {
            return ['status' => 402, 'msg' => "An object with aID $aid does not exist"]; // [recorded]
        }
        $new = (int) $newStatus;
        // [recorded] for downgrades, unknown codes and 50 by a non-admin
        $refused = ['status' => 400, 'msg' => 'Error: ' . self::PREFIX . 'Der Status kann nicht für alle tiefer liegenden Objekte geändert werden.'];

        $nodes = $this->postOrder($object);   // descendants first, the object last, no P
        $changing = array_filter($nodes, fn ($o) => (int) $o['status'] !== $new);
        foreach ($changing as $o) {
            if (!$this->transitionAllowed((int) $o['status'], $new, $user['admin'])) {
                return $refused;
            }
        }

        if (!$changing) {
            return ['status' => 200, 'msg' => 'Success: ' . self::PREFIX . $this->line($object, 'un-changed, thus update omitted')];
        }

        if ($new === 50) {
            $deleted = $this->deleteSubtree($object);
            // [recorded] note the double blank after the colon and <br> without slash
            return ['status' => 200, 'msg' => 'Success: ' . self::PREFIX . implode('', array_map(fn ($a) => " ok: '$a' deleted<br>", $deleted))];
        }

        $lines = '';
        foreach ($changing as $o) {
            $o['status'] = $new;
            $this->touch($o, $user);
            if ($new === 40 && $o['typ'] === 'F') {
                $this->files->deletePrimary($o['aid']);   // [modelled] primary data deleted, metadata kept
            }
            $this->store->put($o);
            $lines .= $this->line($o, 'updated');
        }
        return ['status' => 200, 'msg' => 'Success: ' . self::PREFIX . $lines];
    }

    public function moveObject(array $user, string $aid, string $newPid): array
    {
        $faults = &$this->store->state['faults'];
        if (!empty($faults['failMoveAt'])) {
            $faults['moves'] = ($faults['moves'] ?? 0) + 1;
            if ($faults['moves'] === (int) $faults['failMoveAt']) {
                throw new SimFault('Connection lost during moveObject');
            }
        }

        $object = $this->visible($user, $aid);
        if (!$object) {
            return ['status' => 402, 'msg' => "An object with aID $aid does not exist"]; // [recorded]
        }
        $parent = $this->visible($user, $newPid);
        if (!$parent) {
            return ['status' => 402, 'msg' => "An object with aID $newPid does not exist"]; // [recorded]
        }
        if ($object['pid'] === $newPid) {
            return ['status' => 200, 'msg' => 'Success: ' . self::PREFIX . $this->line($object, 'un-changed, thus update omitted')];
        }
        if (!$this->parentAllowed($object['typ'], $parent['typ']) || $this->isBelow($newPid, $aid)) {
            // [recorded] for an F below an S
            return ['status' => 400, 'msg' => 'Error: ' . self::PREFIX . "Err: cannot move $aid under new parent '$newPid', because of typ"];
        }

        $oldParent = $this->store->get($object['pid']);
        $object['pid'] = $newPid;
        $this->touch($object, $user);
        $this->store->put($object);

        $msg = $this->line($object, 'updated');
        foreach ([$oldParent, $parent] as $p) {
            if ($p && ($line = $this->protocolLine($p, 'updated'))) {
                $msg .= $line;
            }
        }
        return ['status' => 200, 'msg' => 'Success: ' . self::PREFIX . $msg];
    }

    public function getAIDforKeyValue(array $user, string $key, string $value, string $typ): array
    {
        $type = self::KV_TYPES[$typ] ?? null;    // 'Any' and unknown values: every type
        // [recorded] only % is a wildcard; * is taken literally
        $pattern = '/^' . str_replace('%', '.*', preg_quote($value, '/')) . '$/su';

        $hits = [];
        foreach ($this->store->state['objects'] as $o) {
            if ($o['typ'] === 'P' || ($type && $o['typ'] !== $type) || !$this->canSee($user, $o)) {
                continue;
            }
            $candidate = $key === 'aid' ? $o['aid'] : ($this->exportFields($o)[$key] ?? null);
            if ($candidate !== null && preg_match($pattern, (string) $candidate)) {
                $hits[] = $o['aid'];
            }
        }
        sort($hits, SORT_STRING);     // [recorded] alphabetical
        return $this->hitList($hits);
    }

    public function getObjectsChangedSince(array $user, string $since): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            return ['status' => 500, 'msg' => 'Exception: Wrong datetime format!', 'hits' => '', 'aIDList' => '']; // [recorded]
        }
        $hits = [];
        foreach ($this->store->state['objects'] as $o) {
            if ($o['typ'] !== 'P' && $o['changed'] >= $since && $this->canSee($user, $o)) {
                $hits[] = $o;
            }
        }
        // DIMAG's order is its own; the simulator lists the most recently changed first
        usort($hits, fn ($a, $b) => [$b['changed'], $b['ord']] <=> [$a['changed'], $a['ord']]);
        return $this->hitList(array_column($hits, 'aid'));
    }

    // ------------------------------------------------------------------
    // import

    private function collect(\DOMElement $element, ?int $parentIndex, array &$items): void
    {
        $fields = [];
        $children = [];
        foreach ($element->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($node->nodeName === 'verz-obj') {
                $children[] = $node;
            } else {
                $fields[$node->nodeName] = trim($node->textContent);
            }
        }
        $index = count($items);
        $items[] = ['fields' => $fields, 'parent' => $parentIndex, 'aid' => null];
        foreach ($children as $child) {
            $this->collect($child, $index, $items);
        }
    }

    private function import(array $user, ?string $rootid, array &$items, array &$consumed): string
    {
        $objectLines = '';
        $protocolLines = '';
        $existedBefore = array_fill_keys(array_keys($this->store->state['objects']), true);
        $parentsLogged = [];

        foreach ($items as $i => &$item) {
            $f = $item['fields'];
            $pid = ($f['pid'] ?? '') !== '' ? $f['pid']
                : ($item['parent'] !== null ? $items[$item['parent']]['aid'] : ($rootid ?? $this->config->prefix . '-1'));
            $parent = $pid !== null ? $this->store->get($pid) : null;
            if (!$parent || !$this->canSee($user, $parent)) {
                throw new ImportError('getMyAcc(): type of AID is no string'); // [recorded] for an unknown pid
            }
            $aid = ($f['aid'] ?? '') !== '' ? $f['aid'] : null;
            $existing = $aid !== null ? $this->store->get($aid) : null;

            if ($existing) {
                if (!$this->canSee($user, $existing)) {
                    throw new ImportError("Err: no access rights for $aid"); // [modelled]
                }
                if (isset($f['typ']) && $f['typ'] !== $existing['typ']) {
                    throw new ImportError('Err: Der Typ eines Objektes kann nicht geändert werden.'); // [recorded]
                }
                [$updated, $changed] = $this->update($user, $existing, $f, $pid, $parent, $consumed);
                $item['aid'] = $aid;
                $objectLines .= $this->line($updated, $changed ? 'updated' : 'un-changed, thus update omitted');
                continue;
            }

            $typ = $f['typ'] ?? '';
            $title = $f['titel'] ?? '';
            if (!isset(self::PARENT_TYPES[$typ])) {
                throw new ImportError("Insert field failure for title='$title': Err: content for mandatory XML-field='typ' missing"); // [modelled]
            }
            if ($aid !== null && str_starts_with($aid, $this->config->prefix . '-')) {
                throw new ImportError("Err: AID $aid must not use the installation prefix"); // [modelled], documented rule
            }
            if (!$this->parentAllowed($typ, $parent['typ'])) {
                throw new ImportError("Err: cannot insert " . ($aid ?? $title) . " under parent '$pid', because of typ"); // [modelled]
            }
            if ($typ === 'O' && ($f['entstehungs-zeitraum'] ?? '') === '') {
                // [recorded]
                throw new ImportError("Insert field failure for title='$title': Err: content for mandatory XML-field='entstehungs-zeitraum' missing");
            }
            if ($title === '') {
                throw new ImportError("Insert field failure for title='': Err: content for mandatory XML-field='titel' missing"); // [modelled]
            }

            $object = $this->newObject($user, $aid ?? $this->store->nextAid($this->config->prefix), $typ, $pid, $parent, $f);
            if ($typ === 'F') {
                $this->attachFile($object, $f, $consumed);
            }
            $this->store->put($object);
            $item['aid'] = $object['aid'];
            $objectLines .= $this->line($object, 'inserted');

            if ($typ !== 'F') {
                $protocol = $this->newProtocol($user, $object);
                $protocolLines .= "ok: Protokollobjekt {$protocol['aid']} [] : Protokoll zu {$object['aid']}\n{$object['fields']['titel']} inserted<br/>\n";
            }
            if (isset($existedBefore[$pid]) && !isset($parentsLogged[$pid]) && ($line = $this->protocolLine($parent, 'updated'))) {
                $protocolLines .= $line;
                $parentsLogged[$pid] = true;
            }
        }
        unset($item);

        return $objectLines . $protocolLines;
    }

    private function update(array $user, array $object, array $f, string $pid, array $parent, array &$consumed): array
    {
        $before = $object;
        $incoming = $this->storableFields($object['typ'], $f);
        $object['fields'] = array_merge($object['fields'], $incoming);
        if (isset($incoming['signatur-anteil'])) {
            $object['fields']['signatur'] = $this->signature($parent, $incoming['signatur-anteil']);
        }
        if ($pid !== $object['pid']) {
            if (!$this->parentAllowed($object['typ'], $parent['typ'])) {
                throw new ImportError("Err: cannot move {$object['aid']} under new parent '$pid', because of typ");
            }
            $object['pid'] = $pid;
        }
        if ($object['typ'] === 'F' && ($f['sftp-dateiname'] ?? '') !== '') {
            $this->attachFile($object, $f, $consumed);
        }
        $changed = $object['fields'] != $before['fields'] || $object['pid'] !== $before['pid'];
        if ($changed) {
            $this->touch($object, $user);
            $this->store->put($object);
        }
        return [$object, $changed];
    }

    private function attachFile(array &$object, array $f, array &$consumed): void
    {
        $rel = $f['sftp-dateiname'] ?? '';
        $abs = $this->config->sftpDir . '/' . ltrim($rel, '/');
        if ($rel === '' || !is_file($abs)) {
            throw new ImportError("Error: File  $rel not present in import package"); // [recorded], two blanks
        }
        $md5 = md5_file($abs);
        if (($f['md5'] ?? '') !== '' && strtolower($f['md5']) !== $md5) {
            throw new ImportError('Error: md5 mismatch'); // [recorded]
        }
        $this->files->storePrimary($object['aid'], $abs);
        $now = $this->now();
        $object['fields'] = array_merge($object['fields'], [
            'aenderungs-datum-datei' => $f['aenderungs-datum-datei'] ?? date('Y-m-d H:i:s', (int) filemtime($abs)),
            'datei-format' => Files::mimeType($abs),
            'datei-groesse-in-byte' => (string) filesize($abs),
            'dateiname' => $f['dateiname'] ?? basename($rel),
            'md5' => $md5,
        ], Files::characterize($abs));
        $object['changed'] = $now;
        $consumed[] = $abs;
    }

    private function newObject(array $user, string $aid, string $typ, string $pid, array $parent, array $f): array
    {
        $now = $this->now();
        $fields = $this->storableFields($typ, $f);
        if ($typ === 'S' && !isset($fields['merkmal'])) {
            $fields['merkmal'] = 'Serie';  // [recorded] default of a new S
        }
        if (isset($fields['signatur-anteil'])) {
            $fields['signatur'] = $this->signature($parent, $fields['signatur-anteil']);
        }
        $status = (int) ($f['status-code'] ?? 10);
        return [
            'aid' => $aid,
            'typ' => $typ,
            'pid' => $pid,
            'status' => in_array($status, [10, 20], true) ? $status : 10,
            'fields' => $fields,
            'created' => $now,
            'changed' => $now,
            'alt' => self::uuid(),
            'ord' => $this->store->nextOrd(),
            'creator' => $user['name'],
            'modifier' => $user['name'],
        ];
    }

    private function newProtocol(array $user, array $owner): array
    {
        $protocol = $this->newObject($user, $this->store->nextAid($this->config->prefix), 'P', $owner['aid'], $owner,
            ['titel' => 'Protokoll zu ' . $owner['aid']]);
        $this->store->put($protocol);
        return $protocol;
    }

    private function storableFields(string $typ, array $f): array
    {
        $fields = array_diff_key($f, array_flip(self::NOT_STORED));
        if ($typ === 'F') {
            $fields = array_intersect_key($fields, array_flip(self::FILE_FIELDS));
        }
        return array_filter($fields, fn ($v) => $v !== '');
    }

    /**
     * [recorded] the signature joins the signatur-anteil of every ancestor
     * that has one with the object's own ("D IO R 1" in the documentation).
     */
    private function signature(array $parent, string $part): string
    {
        $parts = [$part];
        for ($o = $parent, $guard = 0; $o && $guard < 1000; $o = $o['pid'] !== null ? $this->store->get($o['pid']) : null, $guard++) {
            if (($o['fields']['signatur-anteil'] ?? '') !== '') {
                array_unshift($parts, $o['fields']['signatur-anteil']);
            }
        }
        return implode(' ', $parts);
    }

    // ------------------------------------------------------------------
    // status and deletion

    private function transitionAllowed(int $from, int $to, bool $admin): bool
    {
        return match ($to) {
            20 => $from === 10,
            30 => in_array($from, [10, 20], true),
            40 => $admin && $from === 30,
            50 => $admin && in_array($from, [30, 40], true),
            default => false,
        };
    }

    /** descendants before their parent, younger siblings first, without P [recorded order] */
    private function postOrder(array $object): array
    {
        $out = [];
        $children = array_reverse(array_filter($this->store->children($object['aid']), fn ($c) => $c['typ'] !== 'P'));
        foreach ($children as $child) {
            array_push($out, ...$this->postOrder($child));
        }
        $out[] = $object;
        return $out;
    }

    /** delete a subtree, P included; returns AIDs deepest level first [recorded order] */
    private function deleteSubtree(array $object): array
    {
        $levels = [[$object]];
        while ($last = end($levels)) {
            $next = [];
            foreach ($last as $node) {
                $children = $this->store->children($node['aid']);
                $protocols = array_filter($children, fn ($c) => $c['typ'] === 'P');
                $others = array_reverse(array_filter($children, fn ($c) => $c['typ'] !== 'P'));
                array_push($next, ...array_values($protocols), ...array_values($others));
            }
            if (!$next) {
                break;
            }
            $levels[] = $next;
        }
        $deleted = [];
        foreach (array_reverse($levels) as $level) {
            foreach ($level as $node) {
                $this->store->remove($node['aid']);
                $this->files->deletePrimary($node['aid']);
                $deleted[] = $node['aid'];
            }
        }
        return $deleted;
    }

    // ------------------------------------------------------------------
    // export

    private function exportTree(array $user, array $object, string $repOptions, bool $withLog): array
    {
        $children = array_filter($this->store->children($object['aid']),
            fn ($c) => ($c['typ'] !== 'P' || $withLog) && $this->canSee($user, $c));
        if ($object['typ'] === 'O' && in_array($repOptions, ['first', 'last'], true)) {
            $reps = array_values(array_filter($children, fn ($c) => $c['typ'] === 'R'));
            $keep = $repOptions === 'first' ? array_slice($reps, 0, 1) : array_slice($reps, -1);
            $children = array_filter($children, fn ($c) => $c['typ'] !== 'R' || in_array($c, $keep, true));
        }
        return ['object' => $object, 'children' => array_values(array_map(
            fn ($c) => $this->exportTree($user, $c, $repOptions, $withLog), $children))];
    }

    /**
     * withStruct: the visible ancestors wrap the tree. [modelled] DIMAG
     * counts objects above the account's roots too; the simulator adds the
     * number of hidden ancestors.
     */
    private function wrapAncestors(array $user, array $object, array $tree): array
    {
        $hidden = 0;
        $pid = $object['pid'];
        while ($pid !== null && ($parent = $this->store->get($pid))) {
            if ($this->canSee($user, $parent)) {
                $tree = ['object' => $parent, 'children' => [$tree]];
            } else {
                $hidden++;
            }
            $pid = $parent['pid'];
        }
        return [$tree, $hidden];
    }

    private function countTree(array $tree): int
    {
        return 1 + array_sum(array_map(fn ($c) => $this->countTree($c), $tree['children']));
    }

    private function walk(array $tree, callable $fn): void
    {
        $fn($tree);
        foreach ($tree['children'] as $child) {
            $this->walk($child, $fn);
        }
    }

    /** metadata as DIMAG exports it: stored fields plus system fields, sorted by name, empty ones left out */
    public function exportFields(array $o): array
    {
        $fields = $o['fields'] + [
            'aenderungs-datum' => $o['changed'],
            'aid' => $o['aid'],
            'alternate-id' => $o['alt'],
            'ersteller' => $o['creator'],
            'erstellungs-datum' => $o['created'],
            'geaendert-durch' => $o['modifier'],
            'metadaten-version' => $this->config->metadataVersion,
            'pid' => $o['pid'],
            'status-code' => (string) $o['status'],
            'typ' => $o['typ'],
            'versions-nummer' => '1',
        ];
        if ($o['typ'] === 'F') {
            $fields['sftp-dateiname'] = $o['aid'];
        }
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');
        ksort($fields, SORT_STRING);
        return $fields;
    }

    // ------------------------------------------------------------------
    // helpers

    /** an object the account may see; P only for admins */
    public function visible(array $user, string $aid): ?array
    {
        $o = $this->store->get($aid);
        return $o && $this->canSee($user, $o) ? $o : null;
    }

    private function canSee(array $user, array $o): bool
    {
        if ($user['admin']) {
            return true;
        }
        if ($o['typ'] === 'P') {
            return false;
        }
        if (in_array('*', $user['roots'], true)) {
            return true;
        }
        for ($aid = $o['aid'], $guard = 0; $aid !== null && $guard < 1000; $guard++) {
            if (in_array($aid, $user['roots'], true)) {
                return true;
            }
            $aid = $this->store->get($aid)['pid'] ?? null;
        }
        return false;
    }

    private function isBelow(string $aid, string $ancestor): bool
    {
        for ($guard = 0; $aid !== null && $guard < 1000; $guard++) {
            if ($aid === $ancestor) {
                return true;
            }
            $aid = $this->store->get($aid)['pid'] ?? null;
        }
        return false;
    }

    private function parentAllowed(string $typ, ?string $parentTyp): bool
    {
        return in_array($parentTyp, self::PARENT_TYPES[$typ] ?? [], true);
    }

    private function line(array $o, string $what): string
    {
        $sig = $o['fields']['signatur'] ?? '';
        return 'ok: ' . self::TYPE_NAMES[$o['typ']] . " {$o['aid']} [$sig] : " . ($o['fields']['titel'] ?? '') . " $what<br/>\n";
    }

    private function protocolLine(array $owner, string $what): ?string
    {
        $p = $this->store->protocolOf($owner['aid']);
        if (!$p) {
            return null;
        }
        return "ok: Protokollobjekt {$p['aid']} [] : Protokoll zu {$owner['aid']}\n" . ($owner['fields']['titel'] ?? '') . " $what<br/>\n";
    }

    private function hitList(array $aids): array
    {
        return $aids
            ? ['status' => 200, 'msg' => 'found some objects', 'hits' => count($aids), 'aIDList' => implode(';', $aids)]
            : ['status' => 200, 'msg' => 'nothing appropriate found', 'hits' => 0, 'aIDList' => ''];
    }

    private function touch(array &$o, array $user): void
    {
        $o['changed'] = $this->now();
        $o['modifier'] = $user['name'];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function bool(mixed $v): bool
    {
        return is_bool($v) ? $v : in_array(strtolower(trim((string) $v)), ['1', 'true'], true);
    }

    private static function str(bool $b): string
    {
        return $b ? 'true' : 'false';
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x10);   // time-based layout like DIMAG's, random content
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
