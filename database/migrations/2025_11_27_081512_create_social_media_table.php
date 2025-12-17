<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media', function (Blueprint $table) {
            $table->id();
            $table->string('facebook', 255)->nullable()->default(null);
            $table->string('instagram', 255)->nullable()->default(null);
            $table->string('youtube', 255)->nullable()->default(null);
            $table->string('twitter', 255)->nullable()->default(null);
            $table->string('linkedin', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media');
    }
};
