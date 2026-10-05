<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Updater;

use ZipArchive;

/**
 * A downloaded update archive, inspected before anything on disk changes.
 *
 * @internal
 */
final class Archive
{
    /** @param  list<string>  $files  entry paths (files only, '/' separated) */
    private function __construct(
        public readonly string $path,
        public readonly array $files,
        public readonly int $bytes,        // total uncompressed size
    ) {}

    /**
     * Refuses path traversal, absolute paths, drive letters, symlink entries and oversized archives.
     *
     * @throws UpdateException
     */
    public static function inspect(string $path, int $maxBytes): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new UpdateException('archive_invalid', 'The downloaded file is not a valid ZIP archive.');
        }

        try {
            $files = [];
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

                if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)|^[a-z]:#i', $name) === 1) {
                    throw new UpdateException('archive_unsafe', "Unsafe path in archive: $name");
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attr);

                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new UpdateException('archive_unsafe', "Symlink in archive: $name");
                }
                $bytes += (int) ($stat['size'] ?? 0);

                if ($bytes > $maxBytes) {
                    throw new UpdateException('archive_too_large', 'The archive is larger than max_archive_mb allows.');
                }

                if (! str_ends_with($name, '/')) {
                    $files[] = $name;
                }
            }
        } finally {
            $zip->close();
        }

        return new self($path, $files, $bytes);
    }

    /** @return list<string> first path segments present in the archive */
    public function topLevel(): array
    {
        return array_values(array_unique(array_map(fn (string $f): string => explode('/', $f, 2)[0], $this->files)));
    }

    public function extractTo(string $dir): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            return false;
        }
        $ok = $zip->extractTo($dir);
        $zip->close();

        return $ok;
    }
}
