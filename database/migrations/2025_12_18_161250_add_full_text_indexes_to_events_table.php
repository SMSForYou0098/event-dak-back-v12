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
        // GIN index for full-text search (huge performance boost)
        DB::statement("
            CREATE INDEX events_search_idx ON events 
            USING GIN (to_tsvector('english', COALESCE(name, '') || ' ' || COALESCE(description, '')))
        ");

        // Trigram index for ILIKE queries (optional but helpful)
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX events_name_trgm_idx ON events USING GIN (name gin_trgm_ops)');

        // Index for venue city searches
        DB::statement('CREATE INDEX venues_city_trgm_idx ON venues USING GIN (city gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS venues_city_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS events_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS events_search_idx');
    }
};
