<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The package loads this migration itself; a published copy must not create the table twice.
        if (Schema::hasTable($this->table())) {
            return;
        }

        Schema::create($this->table(), function (Blueprint $table): void {
            $table->string('name', 191)->primary(); // '' = main product, add-on name otherwise
            $table->text('data');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('license-client.store.table', 'license_cache');
    }
};
