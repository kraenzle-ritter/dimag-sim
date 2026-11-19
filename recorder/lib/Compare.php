<?php

declare(strict_types=1);

namespace DimagSim\Recorder;

/**
 * Compares two normalized recordings step by step (contract test: the
 * fixture from a real DIMAG against a run on the simulator).
 *
 * Compared: SOAP status, response fields, msg, ControlFile, faults; hit
 * lists as sets (DIMAG's order of getObjectsChangedSince is its own);
 * SFTP outcome, directory listings and file contents.
 */
final class Compare
{
    /** @param array<string, string> $known step id => why a difference is accepted */
    public static function diff(array $expected, array $actual, array $known = []): array
    {
        $byId = [];
        foreach ($actual['steps'] as $s) {
            $byId[$s['id']] = $s;
        }
        $diffs = [];
        foreach ($expected['steps'] as $e) {
            $id = $e['id'];
            $a = $byId[$id] ?? null;
            if (isset($e['skipped']) || isset($a['skipped'])) {
                continue;
            }
            if ($a === null) {
                $diffs[$id] = ['missing in actual run'];
                continue;
            }
            $d = ($e['type'] ?? '') === 'sftp' ? self::sftp($e, $a) : self::soap($e, $a);
            if ($d && !isset($known[$id])) {
                $diffs[$id] = $d;
            }
        }
        return $diffs;
    }

    private static function soap(array $e, array $a): array
    {
        $d = [];
        if (($e['fault'] ?? null) != ($a['fault'] ?? null)) {
            $d[] = 'fault: expected ' . json_encode($e['fault'] ?? null) . ', got ' . json_encode($a['fault'] ?? null);
        }
        if (($e['http_status'] ?? null) !== ($a['http_status'] ?? null)) {
            $d[] = 'http status: expected ' . json_encode($e['http_status'] ?? null) . ', got ' . json_encode($a['http_status'] ?? null);
        }
        $er = $e['result'] ?? null;
        $ar = $a['result'] ?? null;
        if (!is_array($er) || !is_array($ar)) {
            if ($er !== $ar) {
                $d[] = 'result: expected ' . json_encode($er) . ', got ' . json_encode($ar);
            }
            return $d;
        }
        if (array_keys($er) !== array_keys($ar)) {
            $d[] = 'fields: expected ' . implode(',', array_keys($er)) . ', got ' . implode(',', array_keys($ar));
        }
        foreach ($er as $k => $v) {
            $av = $ar[$k] ?? null;
            if ($k === 'aIDList') {
                $set = function ($x) {
                    $l = array_values(array_filter(explode(';', (string) $x)));
                    sort($l);
                    return $l;
                };
                if ($set($v) !== $set($av)) {
                    $d[] = "aIDList: expected {$v}, got {$av}";
                }
            } elseif ((string) $v !== (string) $av) {
                $d[] = "$k:\n    expected " . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . "\n    got      " . json_encode($av, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $d;
    }

    private static function sftp(array $e, array $a): array
    {
        $d = [];
        // md5 only where no content is kept (a put of our own content contains the run tag)
        $keys = ['ok', 'entries', 'error'];
        if (!array_key_exists('content', $e) && ($e['action'] ?? '') !== 'put') {
            $keys[] = 'md5';
        }
        foreach ($keys as $k) {
            if (($e[$k] ?? null) !== ($a[$k] ?? null)) {
                $d[] = "$k: expected " . json_encode($e[$k] ?? null) . ', got ' . json_encode($a[$k] ?? null);
            }
        }
        if (($e['content'] ?? null) !== ($a['content'] ?? null)) {
            $el = explode("\n", (string) ($e['content'] ?? ''));
            $al = explode("\n", (string) ($a['content'] ?? ''));
            foreach ($el as $i => $line) {
                if (($al[$i] ?? null) !== $line) {
                    $d[] = 'content line ' . ($i + 1) . ":\n    expected " . json_encode($line, JSON_UNESCAPED_UNICODE)
                        . "\n    got      " . json_encode($al[$i] ?? null, JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            if (!$d) {
                $d[] = 'content: expected ' . count($el) . ' lines, got ' . count($al);
            }
        }
        return $d;
    }
}
