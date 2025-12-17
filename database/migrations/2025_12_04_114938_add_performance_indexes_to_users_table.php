<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Enable pg_trgm extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Basic indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index('reporting_user', 'idx_users_reporting_user');
            $table->index(['created_at'], 'idx_users_created_at');
        });

        // Trigram indexes for text columns only
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_name_trgm ON users USING gin(name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_email_trgm ON users USING gin(email gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_organisation_trgm ON users USING gin(organisation gin_trgm_ops)');

        // For bigint column - cast to text
        DB::statement('CREATE INDEX IF NOT EXISTS idx_users_number_trgm ON users USING gin((number::text) gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_reporting_user');
            $table->dropIndex('idx_users_created_at');
        });

        DB::statement('DROP INDEX IF EXISTS idx_users_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_users_email_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_users_number_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_users_organisation_trgm');
    }
};
