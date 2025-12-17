<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('booking_type', 255)->nullable()->default(null);
            $table->string('gateway', 255)->nullable()->default(null);
            $table->text('session_id')->nullable()->default(null);
            $table->longText('set_id')->nullable()->default(null);
            $table->text('payment_id')->nullable()->default(null);
            $table->string('booking_by', 255)->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->string('order_id', 255)->nullable()->default(null);
            $table->string('total_amount', 255)->nullable()->default(null);
            $table->string('discount', 30)->nullable()->default(null);
            $table->longText('payment_method')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_bookings');
    }
};
