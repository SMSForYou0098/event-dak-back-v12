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
        // Fix event_id from boolean to bigInteger (foreign key)
        // Using raw SQL for PostgreSQL compatibility
        // Convert boolean true/false to 1/0, NULL stays NULL
        DB::statement('
            ALTER TABLE event_controls 
            ALTER COLUMN event_id TYPE BIGINT 
            USING CASE 
                WHEN event_id = true THEN 1::BIGINT 
                WHEN event_id = false THEN 0::BIGINT 
                ELSE NULL::BIGINT
            END
        ');
        
        // Update via schema builder to ensure nullable and defaults are set correctly
        Schema::table('event_controls', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to boolean (will convert 0/1 to false/true)
        DB::statement('ALTER TABLE event_controls ALTER COLUMN event_id TYPE BOOLEAN USING CASE WHEN event_id = 0 THEN false WHEN event_id IS NOT NULL THEN true ELSE NULL END');
        
        Schema::table('event_controls', function (Blueprint $table) {
            $table->boolean('event_id')->nullable()->default(null)->change();
        });
    }
};

