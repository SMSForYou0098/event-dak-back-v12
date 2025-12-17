<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Composite index matches queries like:
            // WHERE booking_type = ? AND created_at BETWEEN ...
            $table->index(['booking_type', 'created_at'], 'idx_bookings_type_created_at');
        });

        Schema::table('master_bookings', function (Blueprint $table) {
            $table->index(['booking_type', 'created_at'], 'idx_master_bookings_type_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_type_created_at');
        });

        Schema::table('master_bookings', function (Blueprint $table) {
            $table->dropIndex('idx_master_bookings_type_created_at');
        });
    }
};