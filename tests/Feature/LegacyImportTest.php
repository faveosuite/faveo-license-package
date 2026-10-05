<?php

declare(strict_types=1);

use Faveo\LicenseClient\Http\Network;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Faveo\LicenseClient\State;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('license-client.mode', 'DATABASE');
    config()->set('license-client.addons.calendar', ['product_id' => 12]);
    $this->app->instance(Network::class, $this->billing);
    $this->billing->on('/api/licenseInstall', fn (array $f) => $this->billing->licenceReply($f, 'notification_license_ok'));

    // The old apl_* scripts' tables, as Faveo has them today.
    foreach (['faveo_license' => false, 'plugin_license' => true] as $table => $plugin) {
        Schema::create($table, function (Blueprint $t) use ($plugin): void {
            $t->increments('SETTING_ID');
            foreach (['ROOT_URL', 'CLIENT_EMAIL', 'LICENSE_CODE', 'LCD', 'LRD', 'INSTALLATION_KEY', 'INSTALLATION_HASH'] as $column) {
                $t->string($column)->default('');
            }
            $plugin && $t->string('PLUGIN_NAME');
        });
    }
    DB::table('faveo_license')->insert(['ROOT_URL' => 'https://helpdesk.test', 'CLIENT_EMAIL' => 'a@b.test', 'LICENSE_CODE' => 'MAIN-1']);
    DB::table('plugin_license')->insert([
        ['ROOT_URL' => 'https://helpdesk.test', 'CLIENT_EMAIL' => 'a@b.test', 'LICENSE_CODE' => 'CAL-1', 'PLUGIN_NAME' => 'Calendar'],
        ['ROOT_URL' => 'https://helpdesk.test', 'CLIENT_EMAIL' => 'a@b.test', 'LICENSE_CODE' => 'X-1', 'PLUGIN_NAME' => 'NotConfigured'],
    ]);
});

it('activates the old licences with billing, once, and leaves the old tables alone', function () {
    $this->artisan('license:import-legacy')
        ->expectsOutputToContain('Product: licence installed')
        ->expectsOutputToContain('calendar: licence installed')
        ->assertSuccessful();

    $sent = array_map(fn (array $r): array => [$r['fields']['license_code'], $r['fields']['root_url']], $this->billing->requests);
    expect($sent)->toBe([['MAIN-1', 'https://helpdesk.test'], ['CAL-1', 'https://helpdesk.test']])
        ->and(app(LicenseManager::class)->license()->licenseCode())->toBe('MAIN-1')
        ->and(app(LicenseManager::class)->license('calendar')->licenseCode())->toBe('CAL-1')
        ->and(DB::table('faveo_license')->count())->toBe(1);

    $this->artisan('license:import-legacy')->expectsOutputToContain('Nothing to import')->assertSuccessful();
    expect($this->billing->requests)->toHaveCount(2);
});

it('reports a licence billing refuses and keeps it importable', function () {
    $this->billing->on('/api/licenseInstall', fn (array $f) => $this->billing->licenceReply($f, 'notification_license_expired'));

    $this->artisan('license:import-legacy')->expectsOutputToContain('Product:')->assertFailed();
    expect(app(LicenseManager::class)->license()->licenseCode())->toBeNull();
});

it('skips a table the old script created but never activated', function () {
    DB::table('faveo_license')->update(['LICENSE_CODE' => '']);
    DB::table('plugin_license')->delete();

    $this->artisan('license:import-legacy')->expectsOutputToContain('Nothing to import')->assertSuccessful();
    expect($this->billing->requests)->toBe([]);
});

it('lets a stuck customer remove and reinstall the licence', function () {
    $this->artisan('license:install', ['code' => 'MAIN-1', '--email' => 'a@b.test'])->assertSuccessful();
    DB::table('license_cache')->where('name', '')->update(['data' => '{"license_code":"forged"}']);
    expect(app(LicenseManager::class)->verify()->state)->toBe(State::Tampered);

    // A tampered row no longer blocks install...
    $this->artisan('license:install', ['code' => 'MAIN-1', '--email' => 'a@b.test'])->assertSuccessful();
    // ...and a healthy one can be removed on purpose.
    $this->artisan('license:uninstall')->expectsOutputToContain('Licence removed')->assertSuccessful();
    expect(app(LicenseManager::class)->license()->licenseCode())->toBeNull();
});

it('imports on the update\'s migrate', function () {
    (require __DIR__.'/../../database/migrations/2026_09_28_000000_import_legacy_licenses.php')->up();

    expect(app(LicenseManager::class)->license()->licenseCode())->toBe('MAIN-1');
});
