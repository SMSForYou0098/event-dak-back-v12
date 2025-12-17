<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('easebuzzs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('merchant_key', 255);
            $table->string('salt', 255);
            $table->string('env', 255)->nullable()->default(null);
            $table->string('prod_url', 255)->nullable()->default(null);
            $table->string('test_url', 255)->nullable()->default(null);
            $table->boolean('status')->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('easebuzzs');
    }
};
