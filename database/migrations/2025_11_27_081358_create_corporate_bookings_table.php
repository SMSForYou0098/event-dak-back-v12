<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('token', 255);
            $table->unsignedBigInteger('user_id');
            $table->bigInteger('attendee_id')->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->string('email', 255)->nullable()->default(null);
            $table->string('quantity', 255)->nullable()->default(null);
            $table->decimal('discount', 30,0)->nullable()->default(0);
            $table->decimal('amount', 30,0)->nullable()->default(0);
            $table->decimal('convenience_fee', 30,0)->nullable()->default(0);
            $table->string('base_amount', 10)->nullable()->default(null);
            $table->string('status', 255);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->boolean('is_scaned')->nullable()->default(null);
            $table->timestamp('booking_date')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_bookings');
    }
};
