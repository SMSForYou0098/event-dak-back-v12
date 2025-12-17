<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_configs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 255)->nullable()->default(null);
            $table->string('ground_type', 255)->nullable()->default(null);
            $table->longText('config')->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_configs');
    }
};
