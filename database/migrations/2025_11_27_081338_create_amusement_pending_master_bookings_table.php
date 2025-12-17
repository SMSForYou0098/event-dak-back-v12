<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amusement_pending_master_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('gateway', 255)->nullable()->default(null);
            $table->text('session_id')->nullable()->default(null);
            $table->string('agent_id', 255)->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->string('order_id', 255)->nullable()->default(null);
            $table->string('amount', 255)->nullable()->default(null);
            $table->string('discount', 255)->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amusement_pending_master_bookings');
    }
};
