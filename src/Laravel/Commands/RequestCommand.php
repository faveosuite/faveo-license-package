<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use RuntimeException;

final class RequestCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'license:request {code : Licence code from billing}
        {--addon= : Add-on name from license-client.addons}
        {--url= : This installation\'s URL (default: APP_URL)}';

    protected $description = 'Print the activation code to paste on billing\'s "Activate offline" page (offline licence)';

    /** @return array<string, string> */
    protected function promptForMissingArgumentsUsing(): array
    {
        return ['code' => 'Licence code from billing'];
    }

    public function handle(LicenseManager $licenses): int
    {
        try {
            $code = $licenses->activationCode(
                is_string($c = $this->argument('code')) ? $c : '',
                is_string($a = $this->option('addon')) ? $a : '',
                is_string($u = $this->option('url')) && $u !== '' ? $u : null,
            );
        } catch (RuntimeException $e) {
            $this->components->error($licenses->text($e->getMessage()));

            return self::FAILURE;
        }

        $this->components->info('Activation code');
        $this->line("  <fg=green;options=bold>$code</>");
        $this->newLine();
        $this->components->bulletList([
            'Paste the code on billing\'s "Activate offline" page',
            'Download the licence file, then run: php artisan license:import <file>',
        ]);

        return self::SUCCESS;
    }
}
