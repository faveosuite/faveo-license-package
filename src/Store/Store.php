<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Store;

use RuntimeException;

/**
 * Keeps the small sealed cache row per licence ('' = main product, add-on name otherwise).
 * Implementations only persist; sealing and validation happen in License.
 */
interface Store
{
    /** @return array<string, mixed>|null */
    public function get(string $name): ?array;

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws RuntimeException when the row can't be written
     */
    public function put(string $name, array $row): void;

    public function delete(string $name): void;
}
