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
        // Convert integer columns to decimal for coordinate/dimension precision
        // Using raw SQL for PostgreSQL compatibility
        DB::statement('ALTER TABLE l_sections ALTER COLUMN width TYPE DECIMAL(15, 8) USING COALESCE(width, 0)::DECIMAL');
        DB::statement('ALTER TABLE l_sections ALTER COLUMN height TYPE DECIMAL(15, 8) USING COALESCE(height, 0)::DECIMAL');

        // Update via schema builder to ensure nullable and defaults are set correctly
        Schema::table('l_sections', function (Blueprint $table) {
            $table->decimal('width', 15, 8)->nullable()->default(null)->change();
            $table->decimal('height', 15, 8)->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to integer (will truncate decimal values)
        DB::statement('ALTER TABLE l_sections ALTER COLUMN width TYPE INTEGER USING COALESCE(ROUND(width), 0)::INTEGER');
        DB::statement('ALTER TABLE l_sections ALTER COLUMN height TYPE INTEGER USING COALESCE(ROUND(height), 0)::INTEGER');

        Schema::table('l_sections', function (Blueprint $table) {
            $table->integer('width')->nullable()->default(null)->change();
            $table->integer('height')->nullable()->default(null)->change();
        });
    }
};

