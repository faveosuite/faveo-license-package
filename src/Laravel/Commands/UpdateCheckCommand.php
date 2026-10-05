<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel\Commands;

use DateTimeImmutable;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Faveo\LicenseClient\Updater\UpdateException;
use Faveo\LicenseClient\Updater\Updater;
use Illuminate\Console\Command;

use function Laravel\Prompts\spin;

final class UpdateCheckCommand extends Command
{
    protected $signature = 'license:update-check';

    protected $description = 'List the versions billing offers and whether updates are still included';

    public function handle(Updater $updater, LicenseManager $licenses): int
    {
        if (strtoupper((string) (config('license-client.mode') ?: 'FILE')) === 'FILE') {
            $this->components->warn('This installation uses the offline licence and never contacts billing. Check for updates in your billing account and install the downloaded file with: php artisan license:update --file=<file>');

            return self::FAILURE;
        }

        try {
            $reply = spin(fn () => $updater->versions(), 'Asking billing for versions...');
        } catch (UpdateException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = array_map(fn (array $v): array => [
            $v['version'] ?? '—',
            $v['release_type'] ?? 'official',
            empty($v['is_private']) ? 'public' : 'private',
        ], array_values(array_filter((array) ($reply->data['product_versions'] ?? []), 'is_array')));
        $rows === []
            ? $this->components->warn('Billing returned no versions.')
            : $this->table(['Version', 'Channel', 'Visibility'], $rows);
        $expires = $licenses->expiresAt('updates');
        $expires && $expires < new DateTimeImmutable
            ? $this->components->warn('Your update period ended on '.$expires->format('Y-m-d').'. Renew it in billing to install new versions.')
            : $this->components->info('Updates are included'.($expires ? ' until '.$expires->format('Y-m-d') : '').'.');

        return self::SUCCESS;
    }
}
