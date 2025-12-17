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
        $tables = [
            'event_galleries',
            'event_gates',
            'event_seat_statuses',
            'event_seats',
            'event_att_fields',
            'seat_configs',
            'agent_events',
            'user_tickets',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'event_id')) {
                continue;
            }

            DB::statement("
                ALTER TABLE {$table}
                ALTER COLUMN event_id TYPE BIGINT
                USING CASE
                    WHEN event_id ~ '^[0-9]+$' THEN event_id::BIGINT
                    ELSE NULL::BIGINT
                END
            ");

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('event_id')->nullable()->default(null)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'event_galleries',
            'event_gates',
            'event_seat_statuses',
            'event_seats',
            'event_att_fields',
            'seat_configs',
            'agent_events',
            'user_tickets',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'event_id')) {
                continue;
            }

            DB::statement("
                ALTER TABLE {$table}
                ALTER COLUMN event_id TYPE VARCHAR(255)
                USING COALESCE(event_id::text, '')
            ");

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('event_id', 255)->nullable()->default(null)->change();
            });
        }
    }
};

