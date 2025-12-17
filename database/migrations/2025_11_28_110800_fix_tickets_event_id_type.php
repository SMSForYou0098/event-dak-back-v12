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
        // Fix event_id from string to unsignedBigInteger (foreign key)
        // Using raw SQL for PostgreSQL compatibility
        // Convert string values to integers, NULL stays NULL, non-numeric becomes NULL
        DB::statement('
            ALTER TABLE tickets 
            ALTER COLUMN event_id TYPE BIGINT 
            USING CASE 
                WHEN event_id ~ \'^[0-9]+$\' THEN event_id::BIGINT
                ELSE NULL::BIGINT
            END
        ');
        
        // Update via schema builder to ensure nullable and defaults are set correctly
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to string
        DB::statement('ALTER TABLE tickets ALTER COLUMN event_id TYPE VARCHAR(255) USING COALESCE(event_id::text, \'\')');
        
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('event_id', 255)->nullable()->default(null)->change();
        });
    }
};

