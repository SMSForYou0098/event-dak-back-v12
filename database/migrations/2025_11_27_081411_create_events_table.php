<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 30)->nullable()->default(null);
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->bigInteger('category')->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->text('description')->nullable()->default(null);
            $table->string('event_type', 255)->nullable()->default(null);
            $table->string('date_range', 30)->nullable()->default(null);
            $table->string('entry_time', 255)->nullable()->default(null);
            $table->string('start_time', 30)->nullable()->default(null);
            $table->string('end_time', 30)->nullable()->default(null);
            $table->text('ticket_terms')->nullable()->default(null);
            $table->string('whatsapp_number', 255)->nullable()->default(null);
            $table->string('insta_whts_url', 255)->nullable()->default(null);
            $table->longText('whts_note')->nullable()->default(null);
            $table->longText('booking_notice')->nullable()->default(null);
            $table->string('venue_id', 255)->nullable()->default(null);
            $table->string('artist_id', 255)->nullable()->default(null);
            $table->string('short_url', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
