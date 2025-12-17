<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attndies', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
            $table->boolean('status')->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->integer('user_id')->nullable()->default(null);
            $table->integer('agent_id')->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attndies');
    }
};
