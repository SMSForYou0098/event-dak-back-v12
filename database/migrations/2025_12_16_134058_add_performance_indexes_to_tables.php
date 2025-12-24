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
        // Helper function to check if index exists
        $indexExists = function ($table, $indexName) {
            $connection = Schema::getConnection();
            $schemaName = $connection->getConfig('schema') ?: 'public';

            $result = $connection->selectOne(
                "SELECT EXISTS (
                    SELECT 1 FROM pg_indexes 
                    WHERE schemaname = ? AND indexname = ?
                ) as exists",
                [$schemaName, $indexName]
            );

            return $result->exists ?? false;
        };

        // Bookings table indexes
        Schema::table('bookings', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('bookings', 'idx_bookings_user_created')) {
                $table->index(['user_id', 'created_at'], 'idx_bookings_user_created');
            }
            if (!$indexExists('bookings', 'idx_bookings_type_status')) {
                $table->index(['booking_type', 'status'], 'idx_bookings_type_status');
            }
            if (!$indexExists('bookings', 'idx_bookings_session')) {
                $table->index('session_id', 'idx_bookings_session');
            }
            if (!$indexExists('bookings', 'idx_bookings_ticket')) {
                $table->index('ticket_id', 'idx_bookings_ticket');
            }
            if (!$indexExists('bookings', 'idx_bookings_gateway_type')) {
                $table->index(['gateway', 'booking_type'], 'idx_bookings_gateway_type');
            }
            if (!$indexExists('bookings', 'idx_bookings_created')) {
                $table->index('created_at', 'idx_bookings_created');
            }
            if (!$indexExists('bookings', 'idx_bookings_booking_by')) {
                $table->index('booking_by', 'idx_bookings_booking_by');
            }
        });

        // Tickets table indexes
        Schema::table('tickets', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('tickets', 'idx_tickets_event_status')) {
                $table->index(['event_id', 'status'], 'idx_tickets_event_status');
            }
            if (!$indexExists('tickets', 'idx_tickets_price')) {
                $table->index('price', 'idx_tickets_price');
            }
            if (!$indexExists('tickets', 'idx_tickets_sale_price')) {
                $table->index('sale_price', 'idx_tickets_sale_price');
            }
            if (!$indexExists('tickets', 'idx_tickets_sale_status')) {
                $table->index(['sale', 'status'], 'idx_tickets_sale_status');
            }
        });

        // Events table indexes
        Schema::table('events', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('events', 'idx_events_user_created')) {
                $table->index(['user_id', 'created_at'], 'idx_events_user_created');
            }
            if (!$indexExists('events', 'idx_events_key')) {
                $table->index('event_key', 'idx_events_key');
            }
            if (!$indexExists('events', 'idx_events_category')) {
                $table->index('category', 'idx_events_category');
            }
            if (!$indexExists('events', 'idx_events_venue')) {
                $table->index('venue_id', 'idx_events_venue');
            }
            if (!$indexExists('events', 'idx_events_created')) {
                $table->index('created_at', 'idx_events_created');
            }
        });

        // Master Bookings table indexes
        Schema::table('master_bookings', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('master_bookings', 'idx_master_type_created')) {
                $table->index(['booking_type', 'created_at'], 'idx_master_type_created');
            }
            if (!$indexExists('master_bookings', 'idx_master_booking_by')) {
                $table->index('booking_by', 'idx_master_booking_by');
            }
            if (!$indexExists('master_bookings', 'idx_master_order')) {
                $table->index('order_id', 'idx_master_order');
            }
            if (!$indexExists('master_bookings', 'idx_master_created')) {
                $table->index('created_at', 'idx_master_created');
            }
        });

        // POS Bookings table indexes
        Schema::table('pos_bookings', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('pos_bookings', 'idx_pos_user_created')) {
                $table->index(['user_id', 'created_at'], 'idx_pos_user_created');
            }
            if (!$indexExists('pos_bookings', 'idx_pos_ticket')) {
                $table->index('ticket_id', 'idx_pos_ticket');
            }
            if (!$indexExists('pos_bookings', 'idx_pos_created')) {
                $table->index('created_at', 'idx_pos_created');
            }
        });

        // Attendees table indexes - SKIPPED: email and number columns don't exist in attndies table
        // Schema::table('attndies', function (Blueprint $table) use ($indexExists) {
        //     if (!$indexExists('attndies', 'idx_attendees_email')) {
        //         $table->index('email', 'idx_attendees_email');
        //     }
        //     if (!$indexExists('attndies', 'idx_attendees_number')) {
        //         $table->index('number', 'idx_attendees_number');
        //     }
        // });

        // Payment Logs table indexes
        Schema::table('payment_logs', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('payment_logs', 'idx_payment_session')) {
                $table->index('session_id', 'idx_payment_session');
            }
            if (!$indexExists('payment_logs', 'idx_payment_created')) {
                $table->index('created_at', 'idx_payment_created');
            }
        });

        // Users table indexes (if not already present)
        Schema::table('users', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('users', 'idx_users_email')) {
                $table->index('email', 'idx_users_email');
            }
            if (!$indexExists('users', 'idx_users_reporting')) {
                $table->index('reporting_user', 'idx_users_reporting');
            }
            if (!$indexExists('users', 'idx_users_created')) {
                $table->index('created_at', 'idx_users_created');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes from bookings
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_user_created');
            $table->dropIndex('idx_bookings_type_status');
            $table->dropIndex('idx_bookings_session');
            $table->dropIndex('idx_bookings_ticket');
            $table->dropIndex('idx_bookings_gateway_type');
            $table->dropIndex('idx_bookings_created');
            $table->dropIndex('idx_bookings_booking_by');
        });

        // Drop indexes from tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_event_status');
            $table->dropIndex('idx_tickets_price');
            $table->dropIndex('idx_tickets_sale_price');
            $table->dropIndex('idx_tickets_sale_status');
        });

        // Drop indexes from events
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_user_created');
            $table->dropIndex('idx_events_key');
            $table->dropIndex('idx_events_category');
            $table->dropIndex('idx_events_venue');
            $table->dropIndex('idx_events_created');
        });

        // Drop indexes from master_bookings
        Schema::table('master_bookings', function (Blueprint $table) {
            $table->dropIndex('idx_master_type_created');
            $table->dropIndex('idx_master_booking_by');
            $table->dropIndex('idx_master_order');
            $table->dropIndex('idx_master_created');
        });

        // Drop indexes from pos_bookings
        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->dropIndex('idx_pos_user_created');
            $table->dropIndex('idx_pos_ticket');
            $table->dropIndex('idx_pos_created');
        });

        // Drop indexes from attndies - SKIPPED: indexes were not created
        // Schema::table('attndies', function (Blueprint $table) {
        //     $table->dropIndex('idx_attendees_email');
        //     $table->dropIndex('idx_attendees_number');
        // });

        // Drop indexes from payment_logs
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropIndex('idx_payment_session');
            $table->dropIndex('idx_payment_created');
        });

        // Drop indexes from users
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_email');
            $table->dropIndex('idx_users_reporting');
            $table->dropIndex('idx_users_created');
        });
    }
};
