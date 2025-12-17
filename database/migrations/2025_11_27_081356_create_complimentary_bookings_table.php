<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complimentary_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 255)->nullable()->default(null);
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('ticket_id', 255);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('token', 255);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('email', 255)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->string('status', 255)->nullable()->default(null);
            $table->string('reporting_user', 255)->nullable()->default(null);
            $table->string('type', 10)->nullable()->default(null);
            $table->boolean('is_scaned')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complimentary_bookings');
    }
};
