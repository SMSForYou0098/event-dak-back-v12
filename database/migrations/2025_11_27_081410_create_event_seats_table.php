<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_seats', function (Blueprint $table) {
            $table->id();
            $table->string('seat_id', 255)->nullable()->default(null);
            $table->string('category', 255)->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('config_id', 255)->nullable()->default(null);
            $table->boolean('disabled')->nullable()->default(null);
            $table->boolean('status')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seats');
    }
};
