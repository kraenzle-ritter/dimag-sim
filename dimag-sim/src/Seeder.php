<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/**
 * Initial tree: the repository root {prefix}-1 and, for each account,
 * its root S if it does not exist yet. Further objects come from
 * DIMAG_SIM_SEED (a JSON file) or POST /_sim/seed:
 *
 *   [{"aid": "x-1", "typ": "S", "pid": "sim-2", "titel": "…", "status": 20,
 *     "fields": {"signatur-anteil": "…"}, "content": "primary data of an F"}]
 */
final class Seeder
{
    /** seeded objects look old: a run's getObjectsChangedSince does not list them */
    public const SEED_TIME = '2025-01-01 00:00:00';

    public function __construct(private readonly Config $config, private readonly Store $store, private readonly Files $files)
    {
    }

    public function seed(): void
    {
        $p = $this->config->prefix;
        $objects = [['aid' => "$p-1", 'typ' => 'S', 'pid' => null, 'titel' => $this->config->archiveName,
            'fields' => ['signatur-anteil' => 'SIM'], 'status' => 20]];
        foreach ($this->config->users as $user) {
            foreach ($user['roots'] as $root) {
                if ($root !== '*' && !in_array($root, array_column($objects, 'aid'), true)) {
                    $objects[] = ['aid' => $root, 'typ' => 'S', 'pid' => "$p-1", 'titel' => 'Test archive ' . $root, 'status' => 20,
                        'fields' => ['signatur-anteil' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $root))]];
                }
            }
        }
        $this->add($objects);
        if ($this->config->seedFile && is_file($this->config->seedFile)) {
            $this->add(json_decode((string) file_get_contents($this->config->seedFile), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    /** @return string[] AIDs added */
    public function add(array $objects): array
    {
        $created = [];
        foreach ($objects as $spec) {
            $aid = $spec['aid'] ?? $this->store->nextAid($this->config->prefix);
            if ($this->store->get($aid)) {
                throw new \InvalidArgumentException("$aid exists");
            }
            $pid = $spec['pid'] ?? null;
            if ($pid !== null && !$this->store->get($pid)) {
                throw new \InvalidArgumentException("parent $pid of $aid does not exist");
            }
            $now = $spec['changed'] ?? self::SEED_TIME;
            $fields = ($spec['fields'] ?? []) + ['titel' => $spec['titel'] ?? $aid];
            if (($spec['typ'] ?? '') === 'S') {
                $fields += ['merkmal' => 'Serie'];
            }
            if (isset($fields['signatur-anteil']) && !isset($fields['signatur'])) {
                $parts = [$fields['signatur-anteil']];
                for ($o = $pid !== null ? $this->store->get($pid) : null; $o; $o = $o['pid'] !== null ? $this->store->get($o['pid']) : null) {
                    if (($o['fields']['signatur-anteil'] ?? '') !== '') {
                        array_unshift($parts, $o['fields']['signatur-anteil']);
                    }
                }
                $fields['signatur'] = implode(' ', $parts);
            }
            if (($spec['typ'] ?? '') === 'F') {
                $content = (string) ($spec['content'] ?? "dimag-sim seed $aid\n");
                $this->files->storeContent($aid, $content);
                $fields += ['dateiname' => $aid . '.txt', 'md5' => $spec['md5'] ?? md5($content),
                    'datei-groesse-in-byte' => (string) strlen($content), 'datei-format' => 'text/plain; charset=US-ASCII'];
            }
            $this->store->put([
                'aid' => $aid, 'typ' => $spec['typ'] ?? 'S', 'pid' => $pid, 'status' => (int) ($spec['status'] ?? 20),
                'fields' => $fields, 'created' => $now, 'changed' => $now, 'alt' => Dimag::uuid(),
                'ord' => $this->store->nextOrd(), 'creator' => 'dimag-sim', 'modifier' => 'dimag-sim',
            ]);
            $created[] = $aid;
        }
        // one protocol object per non-F object, as DIMAG keeps them
        foreach ($created as $aid) {
            $o = $this->store->get($aid);
            if (!in_array($o['typ'], ['F', 'P'], true) && !$this->store->protocolOf($aid)) {
                $paid = $this->store->nextAid($this->config->prefix);
                $this->store->put([
                    'aid' => $paid, 'typ' => 'P', 'pid' => $aid, 'status' => $o['status'], 'fields' => ['titel' => "Protokoll zu $aid"],
                    'created' => $o['created'], 'changed' => $o['changed'], 'alt' => Dimag::uuid(), 'ord' => $this->store->nextOrd(),
                    'creator' => 'dimag-sim', 'modifier' => 'dimag-sim',
                ]);
            }
        }
        return $created;
    }
}
