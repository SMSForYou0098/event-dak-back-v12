<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layouts', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('event_key', 255)->nullable()->default(null);
            $table->string('venue_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->longText('stage_config')->nullable()->default(null);
            $table->string('total_section', 255)->nullable()->default(null);
            $table->string('total_row', 255)->nullable()->default(null);
            $table->string('total_seat', 255)->nullable()->default(null);
            $table->longText('meta_data')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layouts');
    }
};
