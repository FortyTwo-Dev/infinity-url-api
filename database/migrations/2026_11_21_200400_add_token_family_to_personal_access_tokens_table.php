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
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('family_id')->nullable();
            $table->text('type')->default('access');
            $table->timestampTz('consumed_at', 6)->nullable();
        });

        DB::statement(<<<'SQL'
            alter table personal_access_tokens
                add constraint personal_access_tokens_type_check
                    check (type in ('access', 'refresh'))
            SQL);

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->index('family_id', 'pat_family');
            $table->index('type', 'pat_type');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('pat_family');
            $table->dropIndex('pat_type');
        });

        DB::statement('alter table personal_access_tokens drop constraint personal_access_tokens_type_check');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['family_id', 'type', 'consumed_at']);
        });
    }
};
