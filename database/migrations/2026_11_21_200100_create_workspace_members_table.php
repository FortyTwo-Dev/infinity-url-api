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
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('role')->default('owner');
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->primary(['workspace_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            alter table workspace_members
                add constraint workspace_members_role_check
                    check (role in ('owner', 'admin', 'member'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
