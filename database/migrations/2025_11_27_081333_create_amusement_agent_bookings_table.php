<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amusement_agent_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('agent_id', 255)->nullable()->default(null);
            $table->bigInteger('attendee_id')->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->decimal('amount', 10,2)->nullable()->default(null);
            $table->string('email', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->string('dates', 255)->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->decimal('discount', 10,2)->nullable()->default(null);
            $table->string('status', 255)->nullable()->default(null);
            $table->string('device', 255)->nullable()->default(null);
            $table->decimal('base_amount', 10,2)->nullable()->default(null);
            $table->decimal('convenience_fee', 10,2)->nullable()->default(null);
            $table->tinyInteger('is_scaned')->nullable()->default(null);
            $table->timestamp('booking_date')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amusement_agent_bookings');
    }
};
