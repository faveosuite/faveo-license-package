<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Tests;

use Faveo\LicenseClient\Laravel\LicenseClientServiceProvider;
use Faveo\LicenseClient\Tests\Support\FakeBilling;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeBilling $billing;

    protected string $licenseFile;

    protected function setUp(): void
    {
        $this->billing = new FakeBilling;
        $this->licenseFile = sys_get_temp_dir().'/lc_feature_'.bin2hex(random_bytes(4)).'.json';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink($this->licenseFile);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [LicenseClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.url', 'https://app.test');
        $app['config']->set('license-client.server_url', 'https://billing.test');
        $app['config']->set('license-client.product_id', 7);
        $app['config']->set('license-client.salt', 'a-long-random-salt-value');
        $app['config']->set('license-client.public_key', $this->billing->publicKey);
        $app['config']->set('license-client.license_file', $this->licenseFile);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
