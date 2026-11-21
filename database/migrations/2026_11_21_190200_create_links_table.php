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
        Schema::create('links', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->text('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained('domains')->restrictOnDelete();
            $table->text('slug');
            $table->text('destination');
            $table->text('status')->default('active');
            $table->timestampTz('expires_at', 6)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
            $table->timestampTz('deleted_at', 6)->nullable();

            // Includes soft-deleted rows: a slug is never reassigned (D-51).
            $table->unique(['domain_id', 'slug'], 'links_domain_slug_unique');
        });

        DB::statement(<<<'SQL'
            alter table links
                add constraint links_status_check
                    check (status in ('active', 'disabled', 'blocked')),
                add constraint links_slug_format
                    check (slug ~ '^[a-z0-9_-]{2,64}$'),
                add constraint links_destination_http
                    check (destination ~* '^https?://')
            SQL);

        DB::statement('create index links_workspace_created on links (workspace_id, created_at desc)');
        DB::statement("create index links_expiring on links (expires_at) where status = 'active' and deleted_at is null");
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
