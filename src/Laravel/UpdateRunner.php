<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use Closure;
use DateTimeImmutable;
use Faveo\LicenseClient\Laravel\Events\UpdateApplied;
use Faveo\LicenseClient\Laravel\Events\UpdateFailed;
use Faveo\LicenseClient\License;
use Faveo\LicenseClient\Status;
use Faveo\LicenseClient\Updater\Backup;
use Faveo\LicenseClient\Updater\UpdateException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

/** The Laravel side of an update: licence gate, maintenance mode, migrations, cache clear, events. */
final class UpdateRunner
{
    public function __construct(
        private readonly LicenseManager $licenses,
        private readonly Kernel $artisan,
        private readonly Dispatcher $events,
        private readonly Config $config,
    ) {}

    /**
     * Online update: download $version of the product, or of an add-on. Not available offline.
     *
     * @throws UpdateException
     */
    public function run(string $version, bool $migrate = true, string $addon = ''): Backup
    {
        return $this->execute($version, $migrate, function (Status $status, Closure $before, Closure $after) use ($version, $addon): Backup {
            if ($this->offline()) {
                throw new UpdateException('offline_mode', 'This installation uses the offline licence and never contacts billing. Download the update file from billing and install it with: php artisan license:update --file=<file>');
            }

            if ($this->updatesExpired($status, $addon)) {
                throw new UpdateException('updates_expired', 'Your update period has ended. Renew it in billing to install new versions.');
            }

            return $this->licenses->updater($addon)->apply($version, $this->licenses->license()->licenseCode(), $before, $after);
        });
    }

    /**
     * Offline update: install an update file downloaded from billing, for the product or for a plugin
     * (recognised by the file's product_id, including a plugin that isn't installed yet). Never
     * contacts billing.
     *
     * @throws UpdateException
     */
    public function runFile(string $path, bool $migrate = true, bool $force = false): Backup
    {
        $bundle = $this->licenses->updater()->openBundle($path);
        $isMain = $bundle->productId === (int) $this->config->get('license-client.product_id');
        $addon = $isMain ? '' : $this->licenses->addonForProduct($bundle->productId);
        $updater = match (true) {
            $isMain => $this->licenses->updater(),
            $addon !== null => $this->licenses->updater($addon),
            default => $this->licenses->updater('new-plugin', ['product_id' => $bundle->productId]), // first install
        };

        return $this->execute($bundle->version, $migrate, function (Status $status, Closure $before, Closure $after) use ($bundle, $updater, $addon, $force): Backup {
            return $updater->applyBundle(
                $bundle,
                // plugin licences are issued on the main licence, so its code identifies the customer
                $this->licenses->license()->licenseCode(),
                $this->licenses->installedVersion($addon ?? ''),
                $this->updatesExpiry($status, $addon ?? ''),
                $force,
                $before,
                $after,
            );
        });
    }

    /**
     * Restore the most recent update backup of the product or an add-on.
     *
     * @throws UpdateException
     */
    public function rollback(string $addon = ''): Backup
    {
        return $this->licenses->updater($addon)->rollback();
    }

    /** An add-on's own licence decides when it has one; otherwise (or for a new plugin) the main one. */
    private function updatesExpiry(Status $main, string $addon): ?DateTimeImmutable
    {
        if ($addon !== '') {
            $own = $this->licenses->status($addon);

            if ($own->ok()) {
                return $own->expiresAt('updates');
            }
        }

        return $main->expiresAt('updates');
    }

    private function updatesExpired(Status $main, string $addon): bool
    {
        $at = $this->updatesExpiry($main, $addon);

        return $at !== null && $at < new DateTimeImmutable;
    }

    /**
     * @param  Closure(Status, Closure(): void, Closure(): void): Backup  $apply
     *
     * @throws UpdateException
     */
    private function execute(string $version, bool $migrate, Closure $apply): Backup
    {
        $down = false;

        try {
            // Offline: a local check of license.json. Online: a fresh check against billing.
            $status = $this->licenses->verify(force: true);

            if (! $status->ok()) {
                throw new UpdateException('license_invalid', $this->licenses->message($status));
            }

            $backup = $apply(
                $status,
                function () use (&$down): void {
                    if ($this->config->get('license-client.updates.maintenance', true)) {
                        $this->artisan->call('down');
                        $down = true;
                    }
                },
                function () use ($migrate): void {
                    if ($migrate && $this->config->get('license-client.updates.run_migrations', true)
                        && $this->artisan->call('migrate', ['--force' => true]) !== 0) {
                        throw new RuntimeException('Migrations failed: '.trim($this->artisan->output()));
                    }
                    // Framework caches only: cache:clear (in optimize:clear) would also wipe app data and
                    // cache-backed sessions, and a cache store error must not fail an installed update.
                    foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
                        $this->artisan->call($command);
                    }
                },
            );
        } catch (UpdateException $e) {
            // Rolled back = old files are back, safe to reopen. Otherwise stay down for an admin.
            if ($down && $e->rolledBack) {
                $this->artisan->call('up');
            }
            $this->events->dispatch(new UpdateFailed($version, $e));

            throw $e;
        }

        $down && $this->artisan->call('up');
        $this->events->dispatch(new UpdateApplied($version));

        return $backup;
    }

    private function offline(): bool
    {
        // null (unset, and no identity file) means FILE, the same default LicenseManager uses
        return strtoupper((string) ($this->config->get('license-client.mode') ?: License::FILE)) === License::FILE;
    }
}
