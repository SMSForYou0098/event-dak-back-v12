<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('ip_address', 255)->nullable()->default(null);
            $table->longText('location')->nullable()->default(null);
            $table->longText('state')->nullable()->default(null);
            $table->longText('country')->nullable()->default(null);
            $table->longText('city')->nullable()->default(null);
            $table->timestamp('login_time')->useCurrent();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
