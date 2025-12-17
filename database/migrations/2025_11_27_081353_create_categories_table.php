<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->string('url', 255)->nullable()->default(null);
            $table->boolean('photo_required')->nullable()->default(0);
            $table->boolean('attendy_required')->nullable()->default(0);
            $table->string('image', 255)->nullable()->default(null);
            $table->longText('card_url')->nullable()->default(null);
            $table->boolean('status')->nullable()->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
