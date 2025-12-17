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
        Schema::table('events', function (Blueprint $table) {
            $table->index('date_range', 'idx_events_date_range');
        });

        Schema::table('event_controls', function (Blueprint $table) {
            $table->index(['status', 'event_id'], 'idx_event_controls_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('idx_events_date_range');
        });

        Schema::table('event_controls', function (Blueprint $table) {
            $table->dropIndex('idx_event_controls_status');
        });
    }
};
