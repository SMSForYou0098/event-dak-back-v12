<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_master_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('session_id', 255)->nullable()->default(null);
            $table->bigInteger('accreditation_id')->nullable()->default(null);
            $table->bigInteger('booking_id')->nullable()->default(null);
            $table->string('order_id', 255)->nullable()->default(null);
            $table->decimal('amount', 10,2)->nullable()->default(null);
            $table->decimal('discount', 10,2)->default(0.00);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_master_bookings');
    }
};
