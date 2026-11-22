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
        Schema::create('reports', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->text('public_id')->unique();
            $table->foreignId('link_id')->nullable()->constrained('links')->nullOnDelete();
            $table->text('submitted_host');
            $table->text('submitted_slug');
            $table->text('category');
            $table->text('details')->nullable();
            // Encrypted at rest by the model cast. Never indexed: a report is
            // never looked up by the reporter's address.
            $table->text('contact_email')->nullable();
            $table->text('state')->default('new');
            $table->text('resolution')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('resolved_at', 6)->nullable();
        });

        DB::statement(<<<'SQL'
            alter table reports
                add constraint reports_category_check
                    check (category in ('phishing', 'malware', 'other')),
                add constraint reports_state_check
                    check (state in ('new', 'reviewing', 'actioned', 'rejected'))
            SQL);

        DB::statement("create index reports_open on reports (state, created_at) where state in ('new', 'reviewing')");
        DB::statement('create index reports_link on reports (link_id) where link_id is not null');
        DB::statement('create index reports_created on reports (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
