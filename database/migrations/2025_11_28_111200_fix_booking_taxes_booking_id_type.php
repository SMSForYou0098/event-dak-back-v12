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
        if (!Schema::hasColumn('booking_taxes', 'booking_id')) {
            return;
        }

        DB::statement("
            ALTER TABLE booking_taxes
            ALTER COLUMN booking_id TYPE BIGINT
            USING CASE
                WHEN booking_id ~ '^[0-9]+$' THEN booking_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('booking_taxes', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('booking_taxes', 'booking_id')) {
            return;
        }

        DB::statement("
            ALTER TABLE booking_taxes
            ALTER COLUMN booking_id TYPE VARCHAR(255)
            USING COALESCE(booking_id::text, '')
        ");

        Schema::table('booking_taxes', function (Blueprint $table) {
            $table->string('booking_id', 255)->nullable()->default(null)->change();
        });
    }
};

