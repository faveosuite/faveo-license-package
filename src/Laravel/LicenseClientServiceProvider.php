<?php

declare(strict_types=1);

namespace Faveo\LicenseClient\Laravel;

use Faveo\LicenseClient\Laravel\Commands\ImportCommand;
use Faveo\LicenseClient\Laravel\Commands\ImportLegacyCommand;
use Faveo\LicenseClient\Laravel\Commands\InstallCommand;
use Faveo\LicenseClient\Laravel\Commands\RequestCommand;
use Faveo\LicenseClient\Laravel\Commands\RollbackCommand;
use Faveo\LicenseClient\Laravel\Commands\StatusCommand;
use Faveo\LicenseClient\Laravel\Commands\UninstallCommand;
use Faveo\LicenseClient\Laravel\Commands\UpdateCheckCommand;
use Faveo\LicenseClient\Laravel\Commands\UpdateCommand;
use Faveo\LicenseClient\Laravel\Middleware\EnsureLicensed;
use Faveo\LicenseClient\Laravel\Middleware\RequireFeature;
use Faveo\LicenseClient\Updater\Updater;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

final class LicenseClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/license-client.php', 'license-client');
        IdentityFile::merge($this->app->make('config'), $this->app->basePath());

        // scoped: one licence check per request, reset between Octane requests and queue jobs
        $this->app->scoped(LicenseManager::class);

        IdentityFile::discoverAddons($this->app->make('config'), $this->app->basePath());

        $this->app->bind(Updater::class, fn (): Updater => $this->app->make(LicenseManager::class)->updater());
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'license-client');
        // Not console-only: web-based updaters run `migrate` through Artisan::call().
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('license', EnsureLicensed::class);
        $router->aliasMiddleware('license.feature', RequireFeature::class);

        Blade::if('licensed', function (?string $feature = null, string $addon = ''): bool {
            $status = $this->app->make(LicenseManager::class)->status($addon);

            return $feature === null ? $status->ok() : $status->allows($feature);
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/license-client.php' => config_path('license-client.php'),
        ], ['license-client', 'license-client-config']);

        $this->publishes([
            __DIR__.'/../../lang' => $this->app->langPath('vendor/license-client'),
        ], ['license-client', 'license-client-lang']);

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], ['license-client', 'license-client-migrations']);

        $this->commands([
            InstallCommand::class,
            RequestCommand::class,
            ImportCommand::class,
            StatusCommand::class,
            UpdateCheckCommand::class,
            UpdateCommand::class,
            RollbackCommand::class,
            UninstallCommand::class,
            ImportLegacyCommand::class,
        ]);
    }
}
