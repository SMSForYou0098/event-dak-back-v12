<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibition_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('token', 255);
            $table->string('agent_id', 255)->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('attendee_id', 255)->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->string('quantity', 255)->nullable()->default(null);
            $table->string('discount', 255)->nullable()->default(null);
            $table->string('amount', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->dateTime('date')->nullable()->default(null);
            $table->string('status', 255);
            $table->boolean('is_scaned')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exhibition_bookings');
    }
};
