<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE seo_configs ALTER COLUMN item_id TYPE INTEGER USING item_id::integer');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE seo_configs ALTER COLUMN item_id TYPE VARCHAR(255) USING item_id::varchar');
    }
};
