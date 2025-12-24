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
        Schema::table('bookings', function (Blueprint $table) {
            // Index for joining with tickets (even if cast is needed, this helps)
            $table->index('ticket_id', 'idx_bookings_ticket_id');
            // Index for date range filtering
            $table->index('created_at', 'idx_bookings_created_at');
            // Composite index for grouping
            $table->index(['booking_type', 'gateway'], 'idx_bookings_type_gateway');
        });

        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->index('ticket_id', 'idx_pos_bookings_ticket_id');
            $table->index('created_at', 'idx_pos_bookings_created_at');
            $table->index('set_id', 'idx_pos_bookings_set_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index('user_id', 'idx_events_user_id');
        });

        Schema::table('event_controls', function (Blueprint $table) {
            // Composite index for join AND status check
            $table->index(['event_id', 'status'], 'idx_event_controls_event_status');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->index('event_id', 'idx_tickets_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Use raw SQL with IF EXISTS to avoid errors if indexes don't exist
        $indexes = [
            'idx_bookings_ticket_id',
            'idx_bookings_created_at',
            'idx_bookings_type_gateway',
            'bookings_ticket_id_index',
            'bookings_created_at_index',
            'bookings_booking_type_gateway_index',
            'idx_pos_bookings_ticket_id',
            'idx_pos_bookings_created_at',
            'idx_pos_bookings_set_id',
            'pos_bookings_ticket_id_index',
            'pos_bookings_created_at_index',
            'pos_bookings_set_id_index',
            'idx_events_user_id',
            'events_user_id_index',
            'idx_event_controls_event_status',
            'event_controls_event_id_status_index',
            'idx_tickets_event_id',
            'tickets_event_id_index',
        ];

        foreach ($indexes as $indexName) {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        }
    }
};
