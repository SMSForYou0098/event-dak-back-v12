<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds a partial unique constraint on user_id and title
     * where deleted_at is null. This ensures that the same user cannot create 
     * two active content masters with the same title, but different users can 
     * have content masters with the same title. Soft-deleted records are excluded.
     */
    public function up(): void
    {
        // Use raw SQL for PostgreSQL partial unique index (only for non-deleted records)
        DB::statement('
            CREATE UNIQUE INDEX content_masters_user_id_title_unique 
            ON content_masters (user_id, title) 
            WHERE deleted_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS content_masters_user_id_title_unique');
    }
};
