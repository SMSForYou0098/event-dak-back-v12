<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->longText('venue_images')->nullable()->default(null);
            $table->string('layout_id', 255)->nullable()->default(null);
            $table->string('org_id', 255);
            $table->string('address', 255)->nullable()->default(null);
            $table->string('city', 255)->nullable()->default(null);
            $table->string('state', 255)->nullable()->default(null);
            $table->string('thumbnail', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->longText('aembeded_code')->nullable()->default(null);
            $table->text('map_url')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
