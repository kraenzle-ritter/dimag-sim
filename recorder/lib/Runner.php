<?php

declare(strict_types=1);

namespace DimagSim\Recorder;

/**
 * Runs a scenario (a list of SOAP and SFTP steps) against a target and
 * returns one record per step. Placeholders in step arguments:
 *   {run}            unique tag of this run (keeps AIDs and paths apart)
 *   {root}           AID of the test parent the run may write below
 *   {sftp_root}      the account's SFTP directory (Inge's sftp_path)
 *   {step.path.to}   a value from an earlier step's result, e.g. {exp.result.ControlFile}
 */
final class Runner
{
    private array $vars;

    /** @var array<string, array> */
    private array $records = [];

    public function __construct(private readonly Target $target, array $vars)
    {
        $this->vars = $vars + ['sftp_root' => $target->sftpRoot()];
    }

    public function run(array $scenario): array
    {
        foreach ($scenario['steps'] as $step) {
            if (isset($step['needs']) && !$this->target->hasAccount($step['needs'])) {
                $this->records[$step['id']] = ['id' => $step['id'], 'skipped' => 'no ' . $step['needs'] . ' account'];
                continue;
            }
            try {
                $record = isset($step['soap']) ? $this->soapStep($step) : $this->sftpStep($step);
            } catch (\Throwable $e) {
                $record = ['error' => get_class($e) . ': ' . $this->target->redact($e->getMessage())];
            }
            $record = ['id' => $step['id']] + (isset($step['note']) ? ['note' => $step['note']] : []) + $record;
            $this->records[$step['id']] = $record;
            fwrite(STDERR, sprintf("  %-28s %s\n", $step['id'], $this->summary($record)));
        }
        return array_values($this->records);
    }

    private function soapStep(array $step): array
    {
        $args = array_map(fn ($a) => $this->expand($a), $step['args'] ?? []);
        $location = isset($step['location']) ? $this->expand($step['location']) : null;
        $r = $this->target->soap($step['soap'], $args, $step['account'] ?? 'user', $location);

        return ['type' => 'soap', 'operation' => $step['soap'], 'account' => $step['account'] ?? 'user', 'args' => $args] + $r;
    }

    private function sftpStep(array $step): array
    {
        $sftp = $this->target->sftp();
        // 'abs' => true: the path is already relative to the SFTP login
        // directory (as DIMAG returns ControlFile paths), not to sftp_root
        $path = !empty($step['abs'])
            ? '/' . ltrim($this->expand($step['path']), '/')
            : $this->target->sftpPath($this->expand($step['path']));
        $out = ['type' => 'sftp', 'action' => $step['sftp'], 'path' => $path];

        switch ($step['sftp']) {
            case 'put':
                $dir = dirname($path);
                if (!$sftp->is_dir($dir)) {
                    $sftp->mkdir($dir, -1, true);
                }
                $out['ok'] = $sftp->put($path, $this->expand($step['content']));
                $out['md5'] = md5($this->expand($step['content']));
                break;
            case 'get':
                $content = $sftp->get($path);
                $out['ok'] = $content !== false;
                $out['content'] = $content === false ? null : $content;
                $out['md5'] = $content === false ? null : md5($content);
                break;
            case 'list':
                $list = $sftp->nlist($path);
                $out['ok'] = $list !== false;
                $out['entries'] = $list === false ? null : array_values(array_diff($list, ['.', '..']));
                sort($out['entries']);
                break;
            case 'exists':
                $out['ok'] = true;
                $out['exists'] = $sftp->file_exists($path);
                break;
            case 'delete_dir':
                // only ever remove this run's working directory or an export DIMAG wrote
                if (!str_contains($path, 'dimagsim-') && !preg_match('#/Export/\d{4}/\d{2}/\d{2}/[^/]+$#', $path)) {
                    throw new \RuntimeException('refusing to delete ' . $path);
                }
                $out['ok'] = $sftp->delete($path, true);
                break;
            default:
                throw new \InvalidArgumentException('unknown sftp action ' . $step['sftp']);
        }
        return $out;
    }

    public function expand(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->expand($v), $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        return preg_replace_callback('/\{([a-z0-9_]+(?:\.[A-Za-z0-9_]+)*)(\|dir)?\}/i', function ($m) {
            $resolved = $this->resolve($m[1]);
            if ($resolved === null) {
                return $m[0];
            }
            return !empty($m[2]) ? dirname($resolved) : $resolved;
        }, $value);
    }

    private function resolve(string $name): ?string
    {
        {
            $parts = explode('.', $name);
            if (count($parts) === 1) {
                return array_key_exists($parts[0], $this->vars) ? (string) $this->vars[$parts[0]] : null;
            }
            $value = $this->records[array_shift($parts)] ?? null;
            foreach ($parts as $p) {
                $value = is_array($value) ? ($value[$p] ?? null) : null;
            }
            return is_scalar($value) && $value !== '' ? (string) $value : null;
        }
    }

    private function summary(array $r): string
    {
        if (isset($r['error'])) {
            return 'ERROR ' . $r['error'];
        }
        if (($r['type'] ?? '') === 'sftp') {
            return 'sftp ' . $r['action'] . ' ' . ($r['ok'] ? 'ok' : 'failed');
        }
        if ($r['fault']) {
            return 'FAULT ' . $r['fault']['faultstring'];
        }
        $res = $r['result'];
        $status = is_array($res) ? ($res['status'] ?? '?') : json_encode($res);
        $msg = is_array($res) ? preg_replace('/\s+/', ' ', substr((string) ($res['msg'] ?? ''), 0, 80)) : '';
        return "status $status {$r['duration']}s $msg";
    }
}
