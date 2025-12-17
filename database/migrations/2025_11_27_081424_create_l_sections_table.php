<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l_sections', function (Blueprint $table) {
            $table->id();
            $table->string('tier_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->boolean('is_blocked')->nullable()->default(null);
            $table->integer('capacity')->nullable()->default(null);
            $table->string('layout_id', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->string('position', 255)->nullable()->default(null);
            $table->integer('width')->nullable()->default(null);
            $table->integer('height')->nullable()->default(null);
            $table->integer('display_order')->nullable()->default(null);
            $table->longText('meta_data')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l_sections');
    }
};
