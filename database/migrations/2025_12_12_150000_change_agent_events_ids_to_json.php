<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Cast existing values safely to JSON
        DB::statement("ALTER TABLE agent_events ALTER COLUMN event_id TYPE JSON USING CASE WHEN event_id IS NULL THEN NULL ELSE to_json(event_id) END");
        DB::statement("ALTER TABLE agent_events ALTER COLUMN ticket_id TYPE JSON USING CASE WHEN ticket_id IS NULL THEN NULL ELSE to_json(ticket_id) END");

        Schema::table('agent_events', function (Blueprint $table) {
            $table->json('event_id')->nullable()->default(null)->change();
            $table->json('ticket_id')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Revert JSON back to previous column types
        // For event_id: Extract first element from JSON array if it's an array, otherwise use the value as-is
        DB::statement("
            ALTER TABLE agent_events 
            ALTER COLUMN event_id TYPE BIGINT 
            USING CASE 
                WHEN event_id IS NULL THEN NULL
                WHEN jsonb_typeof(event_id::jsonb) = 'array' THEN (event_id::jsonb->0)::text::BIGINT
                ELSE (event_id::text)::BIGINT
            END
        ");

        // For ticket_id: Convert JSON to text representation
        DB::statement("
            ALTER TABLE agent_events 
            ALTER COLUMN ticket_id TYPE TEXT 
            USING CASE 
                WHEN ticket_id IS NULL THEN NULL
                ELSE ticket_id::text
            END
        ");

        Schema::table('agent_events', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->default(null)->change();
            $table->longText('ticket_id')->nullable()->default(null)->change();
        });
    }
};
