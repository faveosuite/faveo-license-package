<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

use function Laravel\Prompts\spin;

final class InstallCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'license:install {code : Licence code from billing}
        {--email= : Email the licence was issued to}
        {--addon= : Add-on name from license-client.addons}
        {--url= : This installation\'s URL (default: APP_URL)}';

    protected $description = 'Activate the licence on this installation';

    public function handle(LicenseManager $licenses): int
    {
        $status = spin(fn () => $licenses->install(
            $this->text('email'),
            is_string($code = $this->argument('code')) ? $code : '',
            $this->text('addon'),
            $this->text('url') ?: null,
        ), 'Activating licence...');

        if (! $status->ok()) {
            $this->components->error($licenses->message($status));

            return self::FAILURE;
        }
        $this->components->info('Licence installed.');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    protected function promptForMissingArgumentsUsing(): array
    {
        return ['code' => 'Licence code from billing'];
    }

    private function text(string $option): string
    {
        return is_string($value = $this->option($option)) ? $value : '';
    }
}
