<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable()->default(null);
            $table->longText('description')->nullable()->default(null);
            $table->string('category', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->longText('photo')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
