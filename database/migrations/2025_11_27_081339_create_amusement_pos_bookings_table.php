<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amusement_pos_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('token', 255);
            $table->unsignedBigInteger('user_id');
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->string('quantity', 255)->nullable()->default(null);
            $table->string('discount', 255)->nullable()->default(null);
            $table->string('amount', 255)->nullable()->default(null);
            $table->string('convenience_fee', 255)->nullable()->default(null);
            $table->string('base_amount', 255)->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->boolean('is_scaned')->nullable()->default(null);
            $table->timestamp('booking_date')->nullable()->default(null);
            $table->string('status', 255);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amusement_pos_bookings');
    }
};
