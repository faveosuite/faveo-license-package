<?php

declare(strict_types=1);

use Faveo\LicenseClient\Laravel\Facades\License;
use Faveo\LicenseClient\Laravel\LicenseClientServiceProvider;
use Faveo\LicenseClient\Laravel\LicenseManager;
use Faveo\LicenseClient\State;
use Faveo\LicenseClient\Updater\Updater;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

it('merges config and binds the manager and updater', function () {
    expect(config('license-client.grace_days'))->toBe(7)
        ->and(app(LicenseManager::class))->toBe(app(LicenseManager::class))
        ->and(app(Updater::class))->toBeInstanceOf(Updater::class);
});

it('registers the artisan commands', function () {
    expect(array_keys(Artisan::all()))->toContain('license:install', 'license:request', 'license:import', 'license:status', 'license:update-check', 'license:update', 'license:rollback');
});

it('stores the cache row in the license_cache table', function () {
    $this->billing->writeLicenseFile($this->licenseFile, 7, ['license_domain' => 'app.test']);

    expect(License::install('a@b.test', 'CODE-1')->ok())->toBeTrue()
        ->and(DB::table('license_cache')->where('name', '')->exists())->toBeTrue()
        ->and(License::allows('anything'))->toBeTrue(); // billing sent no entitlements
});

it('translates by case, then state', function () {
    $status = License::verify();

    expect($status->state)->toBe(State::NotInstalled)
        ->and(License::message($status))->toBe('The license is not installed yet.');

    config()->set('license-client.salt', 'x');
    app()->forgetScopedInstances();
    License::clearResolvedInstances();
    expect(License::message(License::verify()))->toContain('LICENSE_SALT');
});

it('builds add-on licences from config and rejects unknown ones', function () {
    config()->set('license-client.addons.calendar', ['product_id' => 12]);

    expect(License::license('calendar')->name())->toBe('calendar')
        ->and(fn () => License::license('nope'))->toThrow(InvalidArgumentException::class);
});

it('registers config, translations and migrations for publishing', function () {
    // asserting the registered paths, not publishing: the testbench app dir is shared by parallel workers
    $paths = fn (string $tag): array => ServiceProvider::pathsToPublish(LicenseClientServiceProvider::class, $tag);

    expect(array_values($paths('license-client-config')))->toBe([config_path('license-client.php')])
        ->and(array_values($paths('license-client-lang')))->toBe([lang_path('vendor/license-client')])
        ->and($paths('license-client'))->toHaveCount(3);
});

it('loads its migrations, so a plain migrate creates license_cache', function () {
    expect(app('migrator')->paths())->toContain(realpath(__DIR__.'/../../src/Laravel').'/../../database/migrations');
});
