<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use Faveo\LicenseClient\Store\Store;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/** Cache rows in the license_cache table (see the package migration). */
final class DatabaseStore implements Store
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $table = 'license_cache',
    ) {}

    public function get(string $name): ?array
    {
        try {
            $json = $this->db->table($this->table)->where('name', $name)->value('data');
        } catch (Throwable) {
            return null; // not migrated yet
        }
        $row = is_string($json) ? json_decode($json, true) : null;

        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    public function put(string $name, array $row): void
    {
        try {
            $this->db->table($this->table)->updateOrInsert(['name' => $name], ['data' => json_encode($row, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        } catch (Throwable $e) {
            throw new RuntimeException("Can't write licence cache: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(string $name): void
    {
        try {
            $this->db->table($this->table)->where('name', $name)->delete();
        } catch (Throwable) {
            // nothing stored
        }
    }
}
