<?php

declare(strict_types=1);

use Faveo\LicenseClient\Http\Network;
use Faveo\LicenseClient\Laravel\Events\UpdateApplied;
use Faveo\LicenseClient\Laravel\Events\UpdateFailed;
use Faveo\LicenseClient\Laravel\IdentityFile;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Faveo\LicenseClient\Laravel\UpdateRunner;
use Faveo\LicenseClient\Updater\UpdateException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;

it('installs and shows the licence from the command line', function () {
    $this->billing->writeLicenseFile($this->licenseFile, 7, ['entitlements' => ['features' => ['sla'], 'limits' => ['agents' => 25]]]);

    $this->artisan('license:install', ['code' => 'CODE-1'])->expectsOutputToContain('Licence installed')->assertSuccessful();
    $this->artisan('license:status')
        ->expectsOutputToContain('active')
        ->expectsOutputToContain('sla')
        ->expectsOutputToContain('Limit: agents')
        ->assertSuccessful();
});

it('fails the install command with a readable reason', function () {
    $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_domain' => 'other.test']);

    $this->artisan('license:install', ['code' => 'CODE-1'])->expectsOutputToContain('different domain')->assertFailed();
});

describe('license:update', function () {
    beforeEach(function () {
        $this->root = tempDir();
        file_put_contents($this->root.'/index.php', 'old');
        $this->update = makeZip($this->root.'/../'.basename($this->root).'-update.zip', ['index.php' => 'new']);
        $this->bundle = fn (array $over = []): string => $this->billing->makeBundle($this->root.'/../'.basename($this->root).'-bundle.zip', $this->update, $over);
        $this->app->instance(Network::class, $this->billing);
        config()->set('license-client.updates.install_dir', $this->root);
        config()->set('license-client.updates.backup_dir', $this->root.'/../'.basename($this->root).'-backups');
        config()->set('license-client.updates.product_key', 'KEY');
        config()->set('license-client.updates.maintenance', false);
        config()->set('license-client.updates.run_migrations', false);
    });

    it('never downloads from billing with the offline licence', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);

        $this->artisan('license:update', ['version' => 'v2'])->expectsOutputToContain('offline licence')->assertFailed();
        $this->artisan('license:update-check')->expectsOutputToContain('offline licence')->assertFailed();
        expect($this->billing->requests)->toBe([]);
    });

    it('refuses to update without a valid licence', function () {
        Event::fake();
        $this->artisan('license:update', ['--file' => ($this->bundle)()])->expectsOutputToContain('not installed')->assertFailed();

        Event::assertDispatched(UpdateFailed::class, fn (UpdateFailed $e) => $e->error->case === 'license_invalid');
        expect(file_get_contents($this->root.'/index.php'))->toBe('old');
    });

    it('installs an offline update file, fires UpdateApplied, and rolls back', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);
        Event::fake([UpdateApplied::class]);

        $this->artisan('license:update', ['--file' => ($this->bundle)()])->expectsOutputToContain('Updated to v2')->assertSuccessful();
        expect(file_get_contents($this->root.'/index.php'))->toBe('new');
        Event::assertDispatched(UpdateApplied::class, fn (UpdateApplied $e) => $e->version === 'v2');

        $this->artisan('license:rollback')->expectsOutputToContain('Restored')->assertSuccessful();
        expect(file_get_contents($this->root.'/index.php'))->toBe('old');
    });

    it('refuses update files it must not install', function (array $manifest, string $message, array $license = []) {
        $this->billing->writeLicenseFile($this->licenseFile, 7, $license + ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);
        config()->set('license-client.updates.current_version', 'v5');

        $this->artisan('license:update', ['--file' => ($this->bundle)($manifest)])->expectsOutputToContain($message)->assertFailed();
        expect(file_get_contents($this->root.'/index.php'))->toBe('old');
    })->with([
        'other customer' => [['license_code' => 'OTHER', 'version' => 'v9'], 'different licence'],
        'another product of theirs' => [['product_id' => 8, 'license_code' => 'OTHER-PRODUCT-CODE', 'version' => 'v9'], 'different licence'],
        'downgrade' => [['version' => 'v4'], 'older than the installed version'],
        'released after update period' => [['version' => 'v9', 'released_at' => '2026-09-01'], 'after your update period ended', ['license_updates_date' => '2026-08-31']],
    ]);

    it('allows a version released inside the update period even after it ended', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2026-09-10']);
        $this->artisan('license:install', ['code' => 'CODE-1']);

        $this->artisan('license:update', ['--file' => ($this->bundle)(['released_at' => '2026-09-01'])])->assertSuccessful();
    });

    it('installs a new plugin from its update file, with its own backups, and then discovers it', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);
        $identity = (string) json_encode(['addon' => 'calendar', 'product_id' => 12, 'version' => 'v1', 'license_file' => 'plugins/Calendar/license.json']);
        $plugin = makeZip($this->root.'/../'.basename($this->root).'-cal.zip', ['plugins/Calendar/Plugin.php' => 'cal', 'plugins/Calendar/license-client.json' => $identity]);
        $bundle = $this->billing->makeBundle($this->root.'/../'.basename($this->root).'-calbundle.zip', $plugin, ['product_id' => 12, 'version' => 'v1']);

        $this->artisan('license:update', ['--file' => $bundle])->expectsOutputToContain('Updated to v1')->assertSuccessful();
        expect(file_get_contents($this->root.'/plugins/Calendar/Plugin.php'))->toBe('cal')
            ->and(glob($this->root.'/../'.basename($this->root).'-backups/addons/12/*/manifest.json'))->toHaveCount(1)
            ->and(glob($this->root.'/../'.basename($this->root).'-backups/*/manifest.json'))->toHaveCount(0); // product backups untouched

        // next boot: the plugin's identity file registers it
        config()->set('license-client.addons_discover', $this->root.'/plugins/*/license-client.json');
        config()->offsetUnset('license-client.addons_discovered');
        IdentityFile::discoverAddons(config(), base_path());
        expect(app(LicenseManager::class)->addonForProduct(12))->toBe('calendar');

        // plugin rollback touches only the plugin
        $this->artisan('license:rollback', ['--addon' => 'calendar'])->assertSuccessful();
        expect(file_exists($this->root.'/plugins/Calendar/Plugin.php'))->toBeFalse()
            ->and(file_get_contents($this->root.'/index.php'))->toBe('old');
    });

    it('never applies the product\'s replace_dirs to a plugin update', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);
        mkdir($this->root.'/vendor/app-package', 0777, true);
        file_put_contents($this->root.'/vendor/app-package/keep.php', 'product code');
        config()->set('license-client.updates.replace_dirs', ['vendor' => []]);
        $plugin = makeZip($this->root.'/../'.basename($this->root).'-p.zip', ['vendor/plugin-lib/a.php' => 'lib']);

        $this->artisan('license:update', ['--file' => $this->billing->makeBundle($this->root.'/../'.basename($this->root).'-pb.zip', $plugin, ['product_id' => 12])])->assertSuccessful();
        expect(file_get_contents($this->root.'/vendor/app-package/keep.php'))->toBe('product code');
    });

    it('never runs a plugin update while the product is updating', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);
        $dir = $this->root.'/../'.basename($this->root).'-backups';
        mkdir($dir, 0777, true);
        $lock = fopen($dir.'/.lock', 'c');
        flock($lock, LOCK_EX);
        $plugin = makeZip($this->root.'/../'.basename($this->root).'-l.zip', ['plugins/X/a.php' => 'x']);

        $this->artisan('license:update', ['--file' => $this->billing->makeBundle($this->root.'/../'.basename($this->root).'-lb.zip', $plugin, ['product_id' => 12])])
            ->expectsOutputToContain('already running')->assertFailed();
    });

    it('stays in maintenance mode when migrations fail after the files changed', function () {
        $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_updates_date' => '2999-01-01']);
        $this->artisan('license:install', ['code' => 'CODE-1']);
        config()->set('license-client.updates.maintenance', true);
        config()->set('license-client.updates.run_migrations', true);

        $artisan = Mockery::mock(Kernel::class);
        $artisan->shouldReceive('call')->with('down')->once()->andReturn(0);
        $artisan->shouldReceive('call')->with('migrate', ['--force' => true])->once()->andReturn(1);
        $artisan->shouldReceive('output')->andReturn('SQLSTATE boom');
        $artisan->shouldNotReceive('call')->with('up');
        $runner = app(UpdateRunner::class, ['artisan' => $artisan]);

        expect(fn () => $runner->runFile(($this->bundle)()))
            ->toThrow(UpdateException::class, 'SQLSTATE boom');
        expect(file_get_contents($this->root.'/index.php'))->toBe('new');
    });
});

it('prints an activation code and imports the downloaded licence file', function () {
    $this->artisan('license:request', ['code' => 'CODE-1'])
        ->expectsOutputToContain('FLC1.')
        ->assertSuccessful();

    $download = sys_get_temp_dir().'/lc_download_'.bin2hex(random_bytes(3)).'.json';
    $this->billing->writeLicenseFile($download, 7, ['license_expire_date' => '2030-06-30']);
    @unlink($this->licenseFile);

    $this->artisan('license:import', ['file' => $download])
        ->expectsOutputToContain('valid until 2030-06-30')
        ->assertSuccessful();
    expect(file_exists($this->licenseFile))->toBeTrue();

    $this->artisan('license:import', ['file' => '/nope.json'])->expectsOutputToContain("Can't read")->assertFailed();
    @unlink($download);
});
