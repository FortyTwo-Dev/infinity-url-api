<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `sources` is a Postgres array: the table is PostgreSQL-only, and the
        // refresh merges provenance instead of overwriting it.
        DB::statement(<<<'SQL'
            create table blocklist_domains (
                domain     text primary key,
                sources    text[] not null,
                first_seen date not null,
                last_seen  date not null
            )
            SQL);

        DB::statement('create index blocklist_domains_last_seen on blocklist_domains (last_seen)');
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_domains');
    }
};
