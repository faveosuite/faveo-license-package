<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\UpdateRunner;
use Faveo\LicenseClient\Updater\UpdateException;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

final class RollbackCommand extends Command
{
    protected $signature = 'license:rollback {--addon= : Roll back a plugin instead of the product}';

    protected $description = 'Restore the files from the most recent update backup (the database is not touched)';

    public function handle(UpdateRunner $runner): int
    {
        try {
            $backup = spin(fn () => $runner->rollback(is_string($a = $this->option('addon')) ? $a : ''), 'Restoring files from backup...');
        } catch (UpdateException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Restored the files replaced by the {$backup->version()} update ({$backup->created()}).");
        $this->line('  Database changes are not undone. Run `php artisan up` once the app is healthy.');

        return self::SUCCESS;
    }
}
