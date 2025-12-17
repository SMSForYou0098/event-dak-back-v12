<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('zone_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->boolean('is_blocked')->nullable()->default(null);
            $table->string('price', 255)->nullable()->default(null);
            $table->integer('capacity')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l_tiers');
    }
};
