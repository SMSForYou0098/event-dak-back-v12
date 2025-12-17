<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_galleries', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 255)->nullable()->default(null);
            $table->longText('thumbnail')->nullable()->default(null);
            $table->longText('layout_image')->nullable()->default(null);
            $table->longText('insta_thumbnail')->nullable()->default(null);
            $table->longText('images')->nullable()->default(null);
            $table->string('youtube_url', 255)->nullable()->default(null);
            $table->string('insta_url', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_galleries');
    }
};
