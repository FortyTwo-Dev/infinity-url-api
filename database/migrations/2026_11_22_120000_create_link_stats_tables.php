<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated counters only. There is deliberately no row per click: erasing a
 * link erases its audience, and no profile can be rebuilt from a counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_stats_daily', function (Blueprint $table): void {
            $table->foreignId('link_id')->constrained('links')->cascadeOnDelete();
            $table->date('date');
            $table->integer('clicks')->default(0);
            $table->integer('uniques')->default(0);
            $table->primary(['link_id', 'date']);
        });

        Schema::create('link_stats_weekly', function (Blueprint $table): void {
            $table->foreignId('link_id')->constrained('links')->cascadeOnDelete();
            $table->smallInteger('iso_year');
            $table->smallInteger('iso_week');
            $table->integer('uniques')->default(0);
            $table->primary(['link_id', 'iso_year', 'iso_week']);
        });

        Schema::create('link_stats_dim', function (Blueprint $table): void {
            $table->foreignId('link_id')->constrained('links')->cascadeOnDelete();
            $table->date('date');
            $table->text('dimension');
            $table->text('value');
            $table->integer('clicks')->default(0);
            $table->primary(['link_id', 'date', 'dimension', 'value']);
        });

        DB::statement(
            "alter table link_stats_dim add constraint link_stats_dim_dimension_check check (dimension in ('country','device'))"
        );

        DB::statement('create index link_stats_daily_date on link_stats_daily (date)');
        DB::statement('create index link_stats_weekly_date on link_stats_weekly (iso_year, iso_week)');
        DB::statement('create index link_stats_dim_date on link_stats_dim (date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('link_stats_dim');
        Schema::dropIfExists('link_stats_weekly');
        Schema::dropIfExists('link_stats_daily');
    }
};
