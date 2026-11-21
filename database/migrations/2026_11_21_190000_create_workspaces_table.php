<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->text('public_id')->unique();
            $table->text('name');
            $table->text('slug')->unique();
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
            $table->timestampTz('deleted_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
