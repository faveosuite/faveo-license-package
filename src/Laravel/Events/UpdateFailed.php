<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Events;

use Faveo\LicenseClient\Updater\UpdateException;

/**
 * An update failed. When $error->rolledBack the files were restored and the app is back up;
 * otherwise (post-update step failed) the app stays in maintenance mode for an admin to fix
 * or run `php artisan license:rollback`.
 */
final class UpdateFailed
{
    public function __construct(
        public readonly string $version,
        public readonly UpdateException $error,
    ) {}
}
