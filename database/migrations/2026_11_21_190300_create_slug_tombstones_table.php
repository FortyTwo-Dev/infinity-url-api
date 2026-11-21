<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_tombstones', function (Blueprint $table): void {
            $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->text('slug');
            $table->timestampTz('erased_at', 6)->useCurrent();

            $table->primary(['domain_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_tombstones');
    }
};
