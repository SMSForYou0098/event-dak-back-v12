<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->string('status', 255)->default(0);
            $table->string('booking_type', 255)->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_bookings');
    }
};
