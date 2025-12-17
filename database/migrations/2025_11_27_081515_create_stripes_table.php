<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('stripe_key', 255);
            $table->string('stripe_secret', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripes');
    }
};
