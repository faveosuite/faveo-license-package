<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Store;

use RuntimeException;

/** Stores cache rows in one JSON file. Keep it outside the public web root. */
final class FileStore implements Store
{
    public function __construct(private readonly string $path) {}

    public function get(string $name): ?array
    {
        $row = $this->all()[$name] ?? null;

        return is_array($row) ? $row : null;
    }

    public function put(string $name, array $row): void
    {
        $all = $this->all();
        $all[$name] = $row;
        $this->write($all);
    }

    public function delete(string $name): void
    {
        $all = $this->all();
        unset($all[$name]);
        $this->write($all);
    }

    /** @return array<mixed> */
    private function all(): array
    {
        $json = is_readable($this->path) ? (string) file_get_contents($this->path) : '';

        return is_array($data = json_decode($json, true)) ? $data : [];
    }

    /** @param  array<mixed>  $all */
    private function write(array $all): void
    {
        $dir = dirname($this->path);
        is_dir($dir) || @mkdir($dir, 0775, true);

        $tmp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($tmp, $this->path)) {
            @unlink($tmp);

            throw new RuntimeException("Can't write licence cache {$this->path}");
        }
    }
}
