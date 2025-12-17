<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paytms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('merchant_id', 255);
            $table->string('merchant_key', 255);
            $table->string('merchant_website', 255);
            $table->string('industry_type', 255);
            $table->string('channel', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paytms');
    }
};
