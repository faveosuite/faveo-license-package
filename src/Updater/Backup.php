<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Updater;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Snapshot of everything an update is about to change, and the way back.
 *
 *   files/     copies of existing files the archive overwrites
 *   dirs/      replace_dirs (e.g. vendor/) renamed out of the way — instant, even for a huge vendor/
 *   manifest   what was overwritten, added and moved, so restore() can undo exactly that
 *
 * @internal
 */
final class Backup
{
    /**
     * @param  array{version: string, created: string, install_dir: string, moved: array<string, list<string>>, overwritten: list<string>, added: list<string>, restored?: bool}  $manifest
     */
    private function __construct(
        public readonly string $dir,
        private array $manifest,
    ) {}

    /**
     * Back up for $archive and move $replaceDirs aside (keeping their $keep sub-paths in place).
     *
     * @param  array<string, list<string>>  $replaceDirs  dir => sub-paths to keep
     *
     * @throws UpdateException when the backup can't be made; anything already moved is put back
     */
    public static function create(string $root, string $installDir, string $version, Archive $archive, array $replaceDirs): self
    {
        $dir = $root.'/'.date('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $version).'-'.bin2hex(random_bytes(2));

        if (! @mkdir($dir.'/files', 0775, true) || ! @mkdir($dir.'/dirs', 0775, true)) {
            throw new UpdateException('backup_failed', "Can't create backup directory $dir");
        }
        $backup = new self($dir, [
            'version' => $version, 'created' => date(DATE_ATOM), 'install_dir' => $installDir,
            'moved' => [], 'overwritten' => [], 'added' => [],
        ]);

        try {
            $top = $archive->topLevel();
            foreach ($replaceDirs as $name => $keep) {
                if (in_array($name, $top, true) && is_dir("$installDir/$name")) {
                    $backup->moveAside($name, $keep);
                }
            }
            foreach ($archive->files as $file) {
                if ($backup->insideMovedDir($file)) {
                    continue; // restored with its whole directory
                }

                if (is_file("$installDir/$file")) {
                    self::copy("$installDir/$file", "$dir/files/$file");
                    $backup->manifest['overwritten'][] = $file;
                } elseif (! file_exists("$installDir/$file")) {
                    $backup->manifest['added'][] = $file;
                }
            }
            $backup->write();
        } catch (UpdateException $e) {
            $backup->restore();

            throw $e;
        }

        return $backup;
    }

    /** @return list<self> newest first */
    public static function all(string $root): array
    {
        $dirs = glob($root.'/*/manifest.json') ?: [];
        rsort($dirs);
        $backups = [];
        foreach ($dirs as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);

            if (is_array($data) && isset($data['version'], $data['install_dir'])) {
                /** @var array{version: string, created: string, install_dir: string, moved: array<string, list<string>>, overwritten: list<string>, added: list<string>, restored?: bool} $data */
                $backups[] = new self(dirname($manifest), $data);
            }
        }

        return $backups;
    }

    /** Delete all but the newest $keep backups. */
    public static function prune(string $root, int $keep): void
    {
        foreach (array_slice(self::all($root), max(0, $keep)) as $old) {
            self::delete($old->dir);
        }
    }

    public function version(): string
    {
        return $this->manifest['version'];
    }

    public function created(): string
    {
        return $this->manifest['created'];
    }

    public function restored(): bool
    {
        return $this->manifest['restored'] ?? false;
    }

    /**
     * Undo the update: delete added files, put overwritten files back, swap moved directories back.
     *
     * @throws UpdateException when a file can't be put back (the backup stays for manual recovery)
     */
    public function restore(): void
    {
        $install = $this->manifest['install_dir'];

        foreach ($this->manifest['added'] as $file) {
            is_file("$install/$file") && @unlink("$install/$file");
        }
        foreach ($this->manifest['overwritten'] as $file) {
            self::copy("{$this->dir}/files/$file", "$install/$file");
        }
        foreach ($this->manifest['moved'] as $name => $keep) {
            $live = "$install/$name";
            $saved = "{$this->dir}/dirs/$name";
            foreach ($keep as $path) {   // kept paths go back into the original tree first
                if (file_exists("$live/$path")) {
                    self::move("$live/$path", "$saved/$path");
                }
            }
            self::delete($live);
            self::move($saved, $live);
        }

        $this->manifest['restored'] = true;
        $this->write();
    }

    /** @param  list<string>  $keep */
    private function moveAside(string $name, array $keep): void
    {
        $install = $this->manifest['install_dir'];
        $saved = "{$this->dir}/dirs/$name";
        self::move("$install/$name", $saved);
        $this->manifest['moved'][$name] = [];
        $this->write(); // recorded before anything else changes, so a crash is still recoverable
        @mkdir("$install/$name", 0775, true);

        foreach ($keep as $path) {
            $path = trim(str_replace('\\', '/', $path), '/');

            if ($path !== '' && file_exists("$saved/$path")) {
                self::move("$saved/$path", "$install/$name/$path");
                $this->manifest['moved'][$name][] = $path;
                $this->write();
            }
        }
    }

    private function insideMovedDir(string $file): bool
    {
        foreach ($this->manifest['moved'] as $name => $keep) {
            if (str_starts_with($file, "$name/")) {
                foreach ($keep as $path) {
                    if (str_starts_with($file, "$name/$path/") || $file === "$name/$path") {
                        return false; // a kept path: its files are backed up one by one
                    }
                }

                return true;
            }
        }

        return false;
    }

    private function write(): void
    {
        if (file_put_contents($this->dir.'/manifest.json', json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new UpdateException('backup_failed', "Can't write {$this->dir}/manifest.json");
        }
    }

    private static function copy(string $from, string $to): void
    {
        is_dir(dirname($to)) || @mkdir(dirname($to), 0775, true);

        if (! @copy($from, $to)) {
            throw new UpdateException('backup_failed', "Can't copy $from to $to");
        }
    }

    private static function move(string $from, string $to): void
    {
        is_dir(dirname($to)) || @mkdir(dirname($to), 0775, true);

        if (! @rename($from, $to)) {
            throw new UpdateException('backup_failed', "Can't move $from to $to (backup_dir must be on the same filesystem as install_dir)");
        }
    }

    public static function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $file->isDir() && ! $file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
