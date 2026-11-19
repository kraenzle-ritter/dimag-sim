<?php

declare(strict_types=1);

namespace DimagSim\Recorder;

/**
 * Stand-in for phpseclib's SFTP when the drop is a local directory
 * (DIMAG_SFTP_HOST=file:///path): the subset of methods the runner uses.
 */
final class LocalDrop
{
    public function __construct(private readonly string $base)
    {
    }

    private function p(string $path): string
    {
        return rtrim($this->base, '/') . '/' . ltrim($path, '/');
    }

    public function is_dir(string $path): bool
    {
        return is_dir($this->p($path));
    }

    public function mkdir(string $path, int $mode = -1, bool $recursive = false): bool
    {
        return is_dir($this->p($path)) || mkdir($this->p($path), 0775, $recursive);
    }

    public function put(string $path, string $data): bool
    {
        return file_put_contents($this->p($path), $data) !== false;
    }

    public function get(string $path): string|false
    {
        return is_file($this->p($path)) ? file_get_contents($this->p($path)) : false;
    }

    public function nlist(string $path): array|false
    {
        return is_dir($this->p($path)) ? scandir($this->p($path)) : false;
    }

    public function file_exists(string $path): bool
    {
        return file_exists($this->p($path));
    }

    public function delete(string $path, bool $recursive = true): bool
    {
        $abs = $this->p($path);
        if (is_file($abs)) {
            return unlink($abs);
        }
        if (!is_dir($abs)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        return rmdir($abs);
    }
}
