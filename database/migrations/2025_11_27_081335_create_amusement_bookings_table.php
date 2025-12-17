<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amusement_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('gateway', 255)->nullable()->default(null);
            $table->text('payment_id')->nullable()->default(null);
            $table->text('session_id')->nullable()->default(null);
            $table->text('attendee_id')->nullable()->default(null);
            $table->string('txnid', 255)->nullable()->default(null);
            $table->string('agent_id', 255)->nullable()->default(null);
            $table->string('promocode_id', 255)->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->decimal('amount', 10,2)->nullable()->default(null);
            $table->decimal('total_tax', 30,0)->nullable()->default(0);
            $table->string('email', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->text('dates')->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->decimal('discount', 10,2)->nullable()->default(null);
            $table->string('status', 255)->nullable()->default(null);
            $table->string('payment_status', 25)->nullable()->default(0);
            $table->string('device', 255)->nullable()->default(null);
            $table->decimal('base_amount', 10,2)->nullable()->default(null);
            $table->decimal('convenience_fee', 10,2)->nullable()->default(null);
            $table->timestamp('booking_date')->nullable()->default(null);
            $table->boolean('is_scaned')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amusement_bookings');
    }
};
