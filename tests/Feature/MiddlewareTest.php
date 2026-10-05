<?php

declare(strict_types=1);

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('license')->get('/dashboard', fn () => 'ok');
    Route::middleware('license')->get('/license/activate', fn () => 'activation page');
    Route::middleware('license.feature:sla')->get('/sla', fn () => 'sla');
});

it('blocks requests without a valid licence but not the excepted paths', function () {
    $this->get('/dashboard')->assertForbidden()->assertSee('not installed');
    $this->get('/license/activate')->assertOk();
});

it('lets licensed requests through and gates features', function () {
    $this->billing->writeLicenseFile($this->licenseFile, 7, ['entitlements' => ['features' => ['reports']]]);
    app(LicenseManager::class)->install('', 'CODE-1', rootUrl: 'https://app.test');

    $this->get('/dashboard')->assertOk();
    $this->get('/sla')->assertForbidden()->assertSee('does not include this feature');
});

it('has a @licensed blade directive', function () {
    $this->billing->writeLicenseFile($this->licenseFile, 7, ['entitlements' => ['features' => ['reports']]]);
    app(LicenseManager::class)->install('', 'CODE-1', rootUrl: 'https://app.test');

    $html = Blade::render("@licensed('reports') yes @endlicensed @licensed('sla') no @endlicensed");

    expect(trim($html))->toBe('yes');
});
