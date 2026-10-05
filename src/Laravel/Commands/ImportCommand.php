<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

use function Laravel\Prompts\spin;

final class ImportCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'license:import {file : Licence file downloaded from billing}
        {--addon= : Add-on name from license-client.addons}
        {--url= : This installation\'s URL (default: APP_URL)}';

    protected $description = 'Install a licence file from billing: activation, renewal or a new server (offline licence)';

    /** @return array<string, string> */
    protected function promptForMissingArgumentsUsing(): array
    {
        return ['file' => 'Path of the licence file downloaded from billing'];
    }

    public function handle(LicenseManager $licenses): int
    {
        $file = is_string($f = $this->argument('file')) ? $f : '';
        $contents = is_file($file) ? @file_get_contents($file) : false;

        if ($contents === false) {
            $this->components->error($licenses->text('license_file_unreadable'));

            return self::FAILURE;
        }

        $status = spin(fn () => $licenses->import(
            $contents,
            is_string($a = $this->option('addon')) ? $a : '',
            is_string($u = $this->option('url')) && $u !== '' ? $u : null,
        ), 'Installing licence file...');

        if (! $status->ok()) {
            $this->components->error($licenses->message($status).' The current licence was not changed.');

            return self::FAILURE;
        }

        $expires = $status->expiresAt('license');
        $this->components->info('Licence installed'.($expires ? ', valid until '.$expires->format('Y-m-d') : '').'.');

        return self::SUCCESS;
    }
}
