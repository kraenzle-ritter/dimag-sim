<?php

// #ddev-generated (DDEV add-on: "ddev add-on get" may overwrite this copy)

declare(strict_types=1);

namespace DimagSim;

/**
 * Primary data and the SFTP drop. The drop is a directory shared with the
 * SFTP container: what DIMAG calls its "sftp Path" (Inge's sftp_path).
 */
final class Files
{
    public function __construct(private readonly string $sftpDir, private readonly string $storeDir)
    {
    }

    public function storePrimary(string $aid, string $source): void
    {
        $this->ensure($this->storeDir);
        copy($source, $this->storeDir . '/' . self::safe($aid));
    }

    public function storeContent(string $aid, string $content): void
    {
        $this->ensure($this->storeDir);
        file_put_contents($this->storeDir . '/' . self::safe($aid), $content);
    }

    /** @param string[] $corrupt AIDs whose exported data should not match their md5 */
    public function exportPrimary(string $aid, string $target, array $corrupt = []): void
    {
        $source = $this->storeDir . '/' . self::safe($aid);
        $content = is_file($source) ? (string) file_get_contents($source) : '';
        if (in_array($aid, $corrupt, true)) {
            $content .= "\n(dimag-sim: corrupted on export)\n";
        }
        file_put_contents($target, $content);
    }

    public function deletePrimary(string $aid): void
    {
        @unlink($this->storeDir . '/' . self::safe($aid));
    }

    /** a fresh export directory, relative to the drop: /Export/YYYY/MM/DD/HH.MM.SS-mmm [recorded layout] */
    public function newExportDir(): string
    {
        do {
            $t = microtime(true);
            $rel = '/Export/' . date('Y/m/d', (int) $t) . '/' . date('H.i.s', (int) $t) . '-' . sprintf('%03d', (int) (fmod($t, 1) * 1000));
            $abs = $this->sftpDir . $rel;
            usleep(1000);
        } while (is_dir($abs));
        $this->ensure($abs);
        return $rel;
    }

    /**
     * After a successful import DIMAG removes the package from the drop
     * [recorded: the directory holding control file and primary file was
     * gone]. The simulator removes the control file, the primary files
     * used, and directories left empty, up to the drop.
     */
    public function consume(string $controlFile, array $primaries): void
    {
        foreach (array_merge([$controlFile], $primaries) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
            for ($dir = dirname($file); strlen($dir) > strlen($this->sftpDir) && str_starts_with($dir, $this->sftpDir); $dir = dirname($dir)) {
                if (!@rmdir($dir)) {
                    break;
                }
            }
        }
    }

    public function reset(): void
    {
        foreach ([$this->storeDir, $this->sftpDir . '/Export'] as $dir) {
            self::removeTree($dir);
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    public static function mimeType(string $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME);
        $mime = (string) $finfo->file($file);
        // DIMAG reports "text/plain; charset=US-ASCII" [recorded]; finfo says "us-ascii"
        return preg_replace_callback('/charset=([\w-]+)/', fn ($m) => 'charset=' . strtoupper($m[1]), $mime);
    }

    /**
     * Format fields DIMAG adds via DROID/JHOVE. The simulator does not
     * identify formats; it reports what DIMAG reported for a plain text
     * file [recorded] and leaves out the PRONOM id.
     */
    public static function characterize(string $file): array
    {
        $fields = [
            'pronom-signature' => 'DROID_SignatureFile_V119.xml',
            'puidformat' => 'Das Format konnte nicht identifiziert werden!',
        ];
        if (str_starts_with(self::mimeType($file), 'text/')) {
            $fields += ['daten-format' => 'ASCII', 'jhoveformat' => 'ASCII'];
        }
        return $fields;
    }

    private static function safe(string $aid): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $aid);
    }

    private function ensure(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
