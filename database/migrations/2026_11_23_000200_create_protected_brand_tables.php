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
        Schema::create('protected_brands', function (Blueprint $table): void {
            $table->text('domain')->primary();
            $table->text('category');
            $table->text('type')->default('registrable');
            $table->text('source')->default('seed');
            $table->date('checked_at');
        });

        DB::statement(<<<'SQL'
            alter table protected_brands
                add constraint protected_brands_type_check
                    check (type in ('registrable', 'hostname'))
            SQL);

        Schema::create('lookalike_domains', function (Blueprint $table): void {
            $table->text('domain')->primary();
            $table->text('brand_domain');
            $table->text('variant_type');
        });

        DB::statement(<<<'SQL'
            alter table lookalike_domains
                add constraint lookalike_domains_variant_type_check
                    check (variant_type in (
                        'substitution', 'deletion', 'insertion', 'transposition',
                        'homoglyph', 'tld', 'combosquat', 'concatenation'
                    ))
            SQL);

        DB::statement('create index lookalike_domains_brand on lookalike_domains (brand_domain)');

        Schema::create('brand_proposals', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->text('token');
            $table->integer('occurrences');
            $table->date('window_from');
            $table->date('window_to');
            $table->text('state')->default('proposed');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->unique(['token', 'window_from', 'window_to']);
        });

        DB::statement(<<<'SQL'
            alter table brand_proposals
                add constraint brand_proposals_state_check
                    check (state in ('proposed', 'accepted', 'rejected'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_proposals');
        Schema::dropIfExists('lookalike_domains');
        Schema::dropIfExists('protected_brands');
    }
};
