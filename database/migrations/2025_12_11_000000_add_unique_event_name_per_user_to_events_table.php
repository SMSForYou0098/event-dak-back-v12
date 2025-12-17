<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds a composite unique constraint on user_id and name.
     * This ensures that the same organizer (user) cannot create two events with the same name,
     * but different organizers can have events with the same name.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Add composite unique index for user_id + name
            // This allows: User A can have "Concert 2025", User B can also have "Concert 2025"
            // But prevents: User A having two events named "Concert 2025"
            $table->unique(['user_id', 'name'], 'events_user_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_user_id_name_unique');
        });
    }
};
