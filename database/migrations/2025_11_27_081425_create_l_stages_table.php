<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l_stages', function (Blueprint $table) {
            $table->id();
            $table->string('layout_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->string('position', 255)->nullable()->default(null);
            $table->string('shape', 255)->nullable()->default(null);
            $table->string('height', 255)->nullable()->default(null);
            $table->string('width', 255)->nullable()->default(null);
            $table->string('status', 255)->nullable()->default(null);
            $table->longText('meta_data')->nullable()->default(null);
            $table->longText('x')->nullable()->default(null);
            $table->longText('y')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l_stages');
    }
};
