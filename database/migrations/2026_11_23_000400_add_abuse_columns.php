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
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('suspended_at', 6)->nullable();
        });

        Schema::table('links', function (Blueprint $table): void {
            // Internal review state: never serialised, never exported.
            $table->timestampTz('review_required_at', 6)->nullable();
            $table->text('review_reason')->nullable();
        });

        DB::statement(<<<'SQL'
            create index links_review_queue on links (review_required_at)
                where review_required_at is not null
            SQL);
    }

    public function down(): void
    {
        DB::statement('drop index if exists links_review_queue');

        Schema::table('links', function (Blueprint $table): void {
            $table->dropColumn(['review_required_at', 'review_reason']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('suspended_at');
        });
    }
};
