<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

final class ImportLegacyCommand extends Command
{
    protected $signature = 'license:import-legacy
        {--table=faveo_license : The old scripts\' product licence table}
        {--plugin-table=plugin_license : The old scripts\' plugin licence table}';

    protected $description = 'Activate the licences left by the old licence scripts (runs once on migrate; this retries it)';

    public function handle(LicenseManager $licenses): int
    {
        $option = fn (string $name, string $default): string => is_string($v = $this->option($name)) && $v !== '' ? $v : $default;
        $results = spin(fn () => $licenses->importLegacy($option('table', 'faveo_license'), $option('plugin-table', 'plugin_license')), 'Importing old licences...');

        if ($results === []) {
            $this->components->info('Nothing to import: no old licence left that is not installed here.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach ($results as $addon => $status) {
            $name = $addon === '' ? 'Product' : $addon;
            $status->ok()
                ? $this->components->info("$name: licence installed.")
                : $this->components->error("$name: ".$licenses->message($status));
            $failed = $failed || ! $status->ok();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
