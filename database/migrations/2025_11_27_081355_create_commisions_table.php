<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('commission_type', 255);
            $table->string('commission_rate', 255);
            $table->string('status', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commisions');
    }
};
