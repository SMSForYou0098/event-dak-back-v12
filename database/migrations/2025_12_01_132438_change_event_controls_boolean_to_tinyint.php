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
        // PostgreSQL requires USING clause for boolean to integer conversion
        $booleanColumns = [
            'scan_detail',
            'event_feature',
            'house_full',
            'online_att_sug',
            'offline_att_sug',
            'multi_scan',
            'show_on_home',
            'ticket_system',
            'booking_by_seat',
            'online_booking',
            'agent_booking',
            'pos_booking',
            'complimentary_booking',
            'exhibition_booking',
            'amusement_booking',
            'accreditation_booking',
            'sponsor_booking',
            'overnight_event',
        ];

        foreach ($booleanColumns as $column) {
            DB::statement("ALTER TABLE event_controls 
                ALTER COLUMN {$column} TYPE SMALLINT 
                USING CASE WHEN {$column} = true THEN 1 ELSE 0 END");
            DB::statement("ALTER TABLE event_controls 
                ALTER COLUMN {$column} SET DEFAULT 0");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $booleanColumns = [
            'scan_detail',
            'event_feature',
            'house_full',
            'online_att_sug',
            'offline_att_sug',
            'multi_scan',
            'show_on_home',
            'ticket_system',
            'booking_by_seat',
            'online_booking',
            'agent_booking',
            'pos_booking',
            'complimentary_booking',
            'exhibition_booking',
            'amusement_booking',
            'accreditation_booking',
            'sponsor_booking',
            'overnight_event',
        ];

        foreach ($booleanColumns as $column) {
            // Drop default first, then change type
            DB::statement("ALTER TABLE event_controls 
                ALTER COLUMN {$column} DROP DEFAULT");
            DB::statement("ALTER TABLE event_controls 
                ALTER COLUMN {$column} TYPE BOOLEAN 
                USING CASE WHEN {$column} = 1 THEN true ELSE false END");
        }
    }
};
