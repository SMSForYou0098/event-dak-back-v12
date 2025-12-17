<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_seat_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('event_key', 255)->nullable()->default(null);
            $table->string('seat_id', 255)->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->string('status', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->string('seat_name', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seat_statuses');
    }
};
