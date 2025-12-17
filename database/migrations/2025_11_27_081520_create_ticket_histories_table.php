<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_histories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ticket_id');
            $table->string('batch_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('currency', 255)->nullable()->default(null);
            $table->decimal('price', 10,2)->nullable()->default(null);
            $table->integer('ticket_quantity')->nullable()->default(null);
            $table->longText('remaining_count')->nullable()->default(null);
            $table->integer('booking_per_customer')->nullable()->default(null);
            $table->string('taxes', 255)->nullable()->default(null);
            $table->boolean('sale')->nullable()->default(null);
            $table->string('sale_date', 255)->nullable()->default(null);
            $table->string('sale_price', 255)->nullable()->default(null);
            $table->boolean('sold_out')->nullable()->default(null);
            $table->boolean('booking_not_open')->nullable()->default(null);
            $table->string('ticket_template', 255)->nullable()->default(null);
            $table->boolean('fast_filling')->nullable()->default(null);
            $table->boolean('status')->nullable()->default(0);
            $table->string('background_image', 255)->nullable()->default(null);
            $table->longText('promocode_ids')->nullable()->default(null);
            $table->softDeletes();
            $table->string('user_booking_limit', 255)->nullable()->default(null);
            $table->boolean('allow_pos')->nullable()->default(null);
            $table->boolean('allow_agent')->nullable()->default(null);
            $table->longText('description')->nullable()->default(null);
            $table->string('access_area', 255)->nullable()->default(null);
            $table->boolean('modify_as')->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_histories');
    }
};
