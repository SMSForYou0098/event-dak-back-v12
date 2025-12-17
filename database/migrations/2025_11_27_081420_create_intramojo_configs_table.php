<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intramojo_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('api_key', 255);
            $table->string('auth_token', 255);
            $table->enum('env', ['test', 'prod'])->default('test');
            $table->string('test_url', 255)->nullable()->default(null);
            $table->string('prod_url', 255)->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intramojo_configs');
    }
};
