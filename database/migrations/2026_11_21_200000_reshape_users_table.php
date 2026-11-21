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
            $table->dropUnique(['email']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['name', 'remember_token']);
        });

        Schema::dropIfExists('password_reset_tokens');

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('password', 'password_hash');
        });

        DB::statement('alter table users alter column email type text');
        DB::statement('alter table users alter column password_hash type text');

        DB::statement(<<<'SQL'
            alter table users
                alter column email_verified_at type timestamptz(6)
                    using email_verified_at at time zone 'UTC',
                alter column created_at type timestamptz(6)
                    using created_at at time zone 'UTC',
                alter column updated_at type timestamptz(6)
                    using updated_at at time zone 'UTC'
            SQL);

        Schema::table('users', function (Blueprint $table): void {
            $table->text('public_id')->unique();
            $table->char('email_hash', 64)->unique();
            $table->text('totp_secret')->nullable();
            $table->timestampTz('totp_confirmed_at', 6)->nullable();
            $table->timestampTz('deleted_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['email_hash']);
            $table->dropColumn(['public_id', 'email_hash', 'totp_secret', 'totp_confirmed_at', 'deleted_at']);
        });

        DB::statement('alter table users alter column email type varchar(255)');
        DB::statement('alter table users alter column password_hash type varchar(255)');

        DB::statement(<<<'SQL'
            alter table users
                alter column email_verified_at type timestamp(0) without time zone
                    using email_verified_at at time zone 'UTC',
                alter column created_at type timestamp(0) without time zone
                    using created_at at time zone 'UTC',
                alter column updated_at type timestamp(0) without time zone
                    using updated_at at time zone 'UTC'
            SQL);

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('password_hash', 'password');
            $table->string('name')->default('');
            $table->rememberToken();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
