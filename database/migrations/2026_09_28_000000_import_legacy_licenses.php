<?php

declare(strict_types=1);

use Faveo\LicenseClient\Laravel\LicenseManager;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Moves licences from the old apl_* tables on the update's migrate; `license:import-legacy` retries it. */
    public function up(): void
    {
        try {
            app(LicenseManager::class)->importLegacy();
        } catch (Throwable $e) {
            report($e); // never fail the update's migrate over this: the customer can still install by hand
        }
    }

    public function down(): void {}
};
