<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Console\Command;

final class UninstallCommand extends Command
{
    protected $signature = 'license:uninstall {--addon= : Remove a plugin licence instead of the product}';

    protected $description = 'Remove the licence from this installation (a licence file, if any, is left alone)';

    public function handle(LicenseManager $licenses): int
    {
        $licenses->uninstall(is_string($a = $this->option('addon')) ? $a : '');
        $this->components->info('Licence removed. Run `php artisan license:install` to install one again.');

        return self::SUCCESS;
    }
}
