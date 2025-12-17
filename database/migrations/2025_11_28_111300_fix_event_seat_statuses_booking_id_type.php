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
        if (!Schema::hasColumn('event_seat_statuses', 'booking_id')) {
            return;
        }

        DB::statement("
            ALTER TABLE event_seat_statuses
            ALTER COLUMN booking_id TYPE BIGINT
            USING CASE
                WHEN booking_id ~ '^[0-9]+$' THEN booking_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('event_seat_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('event_seat_statuses', 'booking_id')) {
            return;
        }

        DB::statement("
            ALTER TABLE event_seat_statuses
            ALTER COLUMN booking_id TYPE VARCHAR(255)
            USING COALESCE(booking_id::text, '')
        ");

        Schema::table('event_seat_statuses', function (Blueprint $table) {
            $table->string('booking_id', 255)->nullable()->default(null)->change();
        });
    }
};

