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
        Schema::create('domains', function (Blueprint $table): void {
            $table->bigInteger('id')->generatedAs()->always()->primary();
            $table->text('public_id')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->text('hostname')->unique();
            $table->text('verification_token');
            $table->text('status')->default('pending');
            $table->timestampTz('verified_at', 6)->nullable();
            $table->text('tls_status')->default('none');
            $table->timestampTz('tls_expires_at', 6)->nullable();
            $table->timestampTz('last_checked_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
            $table->timestampTz('deleted_at', 6)->nullable();
        });

        DB::statement(<<<'SQL'
            alter table domains
                add constraint domains_status_check
                    check (status in ('pending', 'verifying', 'active', 'failed', 'disabled')),
                add constraint domains_tls_status_check
                    check (tls_status in ('none', 'issuing', 'valid', 'expiring', 'expired', 'failed')),
                add constraint domains_hostname_normalized
                    check (hostname = lower(hostname))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
