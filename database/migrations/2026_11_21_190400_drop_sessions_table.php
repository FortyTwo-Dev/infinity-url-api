<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sessions');
    }

    public function down(): void
    {
        // Never recreate the table: it carried visitor identifiers that the
        // privacy invariants forbid in any migration.
    }
};
