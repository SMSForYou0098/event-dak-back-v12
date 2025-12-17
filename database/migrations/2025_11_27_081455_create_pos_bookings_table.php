<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('token', 255);
            $table->text('set_id')->nullable()->default(null);
            $table->unsignedBigInteger('user_id');
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->boolean('seating')->nullable()->default(null);
            $table->string('name', 255)->nullable()->default('Walking Customer');
            $table->string('number', 255)->nullable()->default(0000000000);
            $table->string('quantity', 255)->nullable()->default(null);
            $table->decimal('discount', 30,0)->nullable()->default(0);
            $table->string('price', 255);
            $table->string('amount', 255)->nullable()->default(null);
            $table->string('total_amount', 255)->nullable()->default(null);
            $table->string('total_tax', 255)->nullable()->default(null);
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
        Schema::dropIfExists('pos_bookings');
    }
};
