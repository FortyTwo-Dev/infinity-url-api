<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_slugs', function (Blueprint $table): void {
            $table->text('slug')->primary();
            $table->text('reason')->nullable();
        });

        DB::statement('alter table reserved_slugs add constraint reserved_slugs_lowercase check (slug = lower(slug))');
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_slugs');
    }
};
