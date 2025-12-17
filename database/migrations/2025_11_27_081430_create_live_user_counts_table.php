<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_user_counts', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 255);
            $table->string('location', 255);
            $table->decimal('latitude', 10,7);
            $table->decimal('longitude', 10,7);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_user_counts');
    }
};
