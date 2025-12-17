<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('event_key', 255)->nullable()->default(null);
            $table->longText('promocode_ids')->nullable()->default(null);
            $table->longText('batch_id')->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('currency', 255)->nullable()->default(null);
            $table->bigInteger('price');
            $table->string('ticket_quantity', 255)->nullable()->default(null);
            $table->longText('remaining_count')->nullable()->default(null);
            $table->string('booking_per_customer', 255)->nullable()->default(null);
            $table->string('taxes', 255)->nullable()->default(null);
            $table->boolean('sale')->nullable()->default(0);
            $table->string('sale_label', 255)->nullable()->default(null);
            $table->string('sale_price', 10)->nullable()->default(null);
            $table->text('sale_date')->nullable()->default(null);
            $table->string('background_image', 255)->nullable()->default(null);
            $table->boolean('sold_out')->nullable()->default(0);
            $table->boolean('booking_not_open')->nullable()->default(0);
            $table->string('ticket_template', 30)->nullable()->default(null);
            $table->softDeletes();
            $table->boolean('fast_filling')->nullable()->default(0);
            $table->boolean('status')->default(0);
            $table->string('access_area', 255)->nullable()->default(null);
            $table->boolean('modify_as')->default(0);
            $table->string('user_booking_limit', 255)->nullable()->default(null);
            $table->boolean('allow_pos')->nullable()->default(null);
            $table->boolean('allow_agent')->nullable()->default(null);
            $table->longText('description')->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
