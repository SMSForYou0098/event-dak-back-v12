<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_type', 255)->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('gateway', 255)->nullable()->default(null);
            $table->text('session_id')->nullable()->default(null);
            $table->longText('set_id')->nullable()->default(null);
            $table->text('payment_id')->nullable()->default(null);
            $table->bigInteger('attendee_id')->nullable()->default(null);
            $table->string('txnid', 255)->nullable()->default(null);
            $table->string('booking_by', 255)->nullable()->default(null);
            $table->string('promocode_id', 255)->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->string('master_token', 255)->nullable()->default(null);
            $table->string('quantity', 255)->nullable()->default(null);
            $table->string('email', 255)->nullable()->default(null);
            $table->string('name', 30)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->text('dates')->nullable()->default(null);
            $table->longText('payment_method')->nullable()->default(null);
            $table->decimal('discount', 30,0)->nullable()->default(null);
            $table->string('status', 255);
            $table->string('device', 30)->nullable()->default(null);
            $table->softDeletes();
            $table->decimal('total_amount', 10,2)->nullable()->default(null);
            $table->boolean('is_scaned')->nullable()->default(null);
            $table->longText('batch_id')->nullable()->default(null);
            $table->string('ess_id', 255)->nullable()->default(null);
            $table->string('seat_id', 255)->nullable()->default(null);
            $table->string('row_id', 255)->nullable()->default(null);
            $table->string('section_id', 255)->nullable()->default(null);
            $table->string('seat_name', 255)->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
