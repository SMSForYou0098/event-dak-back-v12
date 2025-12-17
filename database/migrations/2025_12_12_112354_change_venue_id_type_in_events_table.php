<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL with USING clause for PostgreSQL type conversion
        DB::statement('ALTER TABLE events ALTER COLUMN venue_id TYPE bigint USING venue_id::bigint');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to varchar
        DB::statement('ALTER TABLE events ALTER COLUMN venue_id TYPE varchar(255) USING venue_id::varchar');
    }
};
