<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\UpdateRunner;
use Faveo\LicenseClient\Updater\UpdateException;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

final class UpdateCommand extends Command
{
    protected $signature = 'license:update {version? : Version to download from billing, e.g. v9.1.0 (online licence)}
        {--file= : Update file downloaded from billing (offline licence); plugins are recognised automatically}
        {--addon= : With a version: update this plugin instead of the product (online licence)}
        {--force : Allow installing an older version from --file}
        {--no-migrate : Skip migrate --force after the files are in place}';

    protected $description = 'Install a new version: backed up first, rolled back if extraction fails';

    public function handle(UpdateRunner $runner): int
    {
        $version = is_string($v = $this->argument('version')) ? $v : '';
        $file = is_string($f = $this->option('file')) ? $f : '';

        if (($version === '') === ($file === '')) {
            $this->components->error('Pass either a version (online licence) or --file=<update file> (offline licence).');

            return self::FAILURE;
        }

        try {
            $backup = spin(fn () => $file !== ''
                ? $runner->runFile($file, ! $this->option('no-migrate'), (bool) $this->option('force'))
                : $runner->run($version, ! $this->option('no-migrate'), is_string($a = $this->option('addon')) ? $a : ''),
                'Installing update (backup, replace files, migrate)...');
        } catch (UpdateException $e) {
            $this->components->error($e->getMessage());
            $this->line($e->rolledBack
                ? '  Files were restored from the backup; the application is unchanged.'
                : '  If files were already replaced, the application may be in maintenance mode. Fix the error, or run: php artisan license:rollback');

            return self::FAILURE;
        }

        $this->components->info("Updated to {$backup->version()}. Backup: {$backup->dir}");

        return self::SUCCESS;
    }
}
