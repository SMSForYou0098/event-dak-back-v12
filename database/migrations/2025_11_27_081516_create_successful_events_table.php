<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('successful_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('title', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->string('thumbnail', 255)->nullable()->default(null);
            $table->string('url', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('successful_events');
    }
};
