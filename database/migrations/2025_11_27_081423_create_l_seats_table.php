<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l_seats', function (Blueprint $table) {
            $table->id();
            $table->string('section_id', 255)->nullable()->default(null);
            $table->string('row_id', 255)->nullable()->default(null);
            $table->string('seat_no', 255)->nullable()->default(null);
            $table->string('status', 255)->nullable()->default(null);
            $table->boolean('is_booked')->nullable()->default(null);
            $table->string('price', 255)->nullable()->default(null);
            $table->integer('capacity')->nullable()->default(null);
            $table->string('label', 255)->nullable()->default(null);
            $table->string('position', 255)->nullable()->default(null);
            $table->string('seat_reading', 255)->nullable()->default(null);
            $table->longText('seat_icon')->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->longText('meta_data')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l_seats');
    }
};
