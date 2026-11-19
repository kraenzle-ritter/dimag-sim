<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/**
 * The simulated object tree, kept in one JSON file. Each request runs
 * under an exclusive lock, so concurrent requests see a consistent tree.
 *
 * Object: aid, typ (S|O|R|F|P), pid, status, fields (metadata as DIMAG
 * stores it), created, changed, alt (alternate-id), ord (insertion
 * order), creator, modifier.
 */
final class Store
{
    /** @var resource|null */
    private $lock = null;

    public array $state = [];

    public function __construct(private readonly string $file)
    {
    }

    public function begin(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->lock = fopen($this->file . '.lock', 'c');
        flock($this->lock, LOCK_EX);
        $json = is_file($this->file) ? file_get_contents($this->file) : '';
        $this->state = $json !== '' ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
        $this->state += ['seq' => 0, 'ord' => 0, 'objects' => [], 'faults' => [], 'clock' => null, 'exports' => 0];
    }

    public function commit(): void
    {
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $this->file);
        $this->release();
    }

    public function release(): void
    {
        if ($this->lock) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function initialized(): bool
    {
        return !empty($this->state['initialized']);
    }

    public function get(string $aid): ?array
    {
        return $this->state['objects'][$aid] ?? null;
    }

    public function put(array $object): void
    {
        $this->state['objects'][$object['aid']] = $object;
    }

    public function remove(string $aid): void
    {
        unset($this->state['objects'][$aid]);
    }

    /** @return array<int, array> children in insertion order */
    public function children(string $aid): array
    {
        $children = array_filter($this->state['objects'], fn ($o) => $o['pid'] === $aid);
        usort($children, fn ($a, $b) => $a['ord'] <=> $b['ord']);
        return $children;
    }

    public function protocolOf(string $aid): ?array
    {
        foreach ($this->state['objects'] as $o) {
            if ($o['typ'] === 'P' && $o['pid'] === $aid) {
                return $o;
            }
        }
        return null;
    }

    public function nextAid(string $prefix): string
    {
        do {
            $aid = $prefix . '-' . (++$this->state['seq']);
        } while (isset($this->state['objects'][$aid]));
        return $aid;
    }

    public function nextOrd(): int
    {
        return ++$this->state['ord'];
    }
}
