<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Bookings table indexes
        Schema::table('bookings', function (Blueprint $table) {
            // Composite index for gateway-wise and channel queries
            $table->index(
                ['booking_type', 'gateway', 'created_at', 'deleted_at'],
                'bookings_type_gateway_created_deleted_idx'
            );

            // Index for session_id (used in DISTINCT counts)
            $table->index('session_id', 'bookings_session_id_idx');

            // Partial index for non-deleted records (PostgreSQL specific)
            // This is more efficient as it only indexes active records
        });

        // POS Bookings table indexes
        Schema::table('pos_bookings', function (Blueprint $table) {
            // Composite index for date range queries
            $table->index(
                ['created_at', 'deleted_at'],
                'pos_bookings_created_deleted_idx'
            );
        });

        // PostgreSQL partial indexes (more efficient for soft deletes)
        DB::statement('
            CREATE INDEX IF NOT EXISTS bookings_active_type_gateway_idx 
            ON bookings (booking_type, gateway, created_at) 
            WHERE deleted_at IS NULL
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS pos_bookings_active_created_idx 
            ON pos_bookings (created_at) 
            WHERE deleted_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_type_gateway_created_deleted_idx');
            $table->dropIndex('bookings_session_id_idx');
        });

        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->dropIndex('pos_bookings_created_deleted_idx');
        });

        // Drop partial indexes
        DB::statement('DROP INDEX IF EXISTS bookings_active_type_gateway_idx');
        DB::statement('DROP INDEX IF EXISTS pos_bookings_active_created_idx');
    }
};