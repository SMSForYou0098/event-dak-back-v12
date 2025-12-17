<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Enable pg_trgm extension for text search (if not already enabled)
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // =====================
        // BOOKINGS TABLE INDEXES
        // =====================
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_created_at ON bookings (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_booking_by ON bookings (booking_by)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_ticket_id ON bookings (ticket_id)');

        // Trigram indexes for ILIKE search on bookings
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_name_trgm ON bookings USING gin(name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_email_trgm ON bookings USING gin(email gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_session_id_trgm ON bookings USING gin(session_id gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_bookings_number_trgm ON bookings USING gin((number::text) gin_trgm_ops)');

        // =====================
        // MASTER_BOOKINGS TABLE INDEXES
        // =====================
        DB::statement('CREATE INDEX IF NOT EXISTS idx_master_bookings_created_at ON master_bookings (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_master_bookings_booking_by ON master_bookings (booking_by)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_master_bookings_user_id ON master_bookings (user_id)');

        // Trigram indexes for ILIKE search on master_bookings
        DB::statement('CREATE INDEX IF NOT EXISTS idx_master_bookings_session_id_trgm ON master_bookings USING gin(session_id gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_master_bookings_order_id_trgm ON master_bookings USING gin(order_id gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_master_bookings_payment_id_trgm ON master_bookings USING gin(payment_id gin_trgm_ops)');

        // =====================
        // TICKETS TABLE INDEXES
        // =====================
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tickets_event_id ON tickets (event_id)');

        // Trigram index for ticket name search
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tickets_name_trgm ON tickets USING gin(name gin_trgm_ops)');

        // =====================
        // EVENTS TABLE INDEXES
        // =====================
        DB::statement('CREATE INDEX IF NOT EXISTS idx_events_user_id ON events (user_id)');

        // Trigram index for event name search
        DB::statement('CREATE INDEX IF NOT EXISTS idx_events_name_trgm ON events USING gin(name gin_trgm_ops)');
    }

    public function down(): void
    {
        // Drop bookings indexes
        DB::statement('DROP INDEX IF EXISTS idx_bookings_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_bookings_booking_by');
        DB::statement('DROP INDEX IF EXISTS idx_bookings_ticket_id');
        DB::statement('DROP INDEX IF EXISTS idx_bookings_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_bookings_email_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_bookings_session_id_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_bookings_number_trgm');

        // Drop master_bookings indexes
        DB::statement('DROP INDEX IF EXISTS idx_master_bookings_created_at');
        DB::statement('DROP INDEX IF EXISTS idx_master_bookings_booking_by');
        DB::statement('DROP INDEX IF EXISTS idx_master_bookings_user_id');
        DB::statement('DROP INDEX IF EXISTS idx_master_bookings_session_id_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_master_bookings_order_id_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_master_bookings_payment_id_trgm');

        // Drop tickets indexes
        DB::statement('DROP INDEX IF EXISTS idx_tickets_event_id');
        DB::statement('DROP INDEX IF EXISTS idx_tickets_name_trgm');

        // Drop events indexes
        DB::statement('DROP INDEX IF EXISTS idx_events_user_id');
        DB::statement('DROP INDEX IF EXISTS idx_events_name_trgm');
    }
};
