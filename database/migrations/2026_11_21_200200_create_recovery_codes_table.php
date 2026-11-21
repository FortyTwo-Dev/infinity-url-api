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
        Schema::create('recovery_codes', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('code_hash');
            $table->timestampTz('used_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement('create index recovery_codes_user on recovery_codes (user_id) where used_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');
    }
};
