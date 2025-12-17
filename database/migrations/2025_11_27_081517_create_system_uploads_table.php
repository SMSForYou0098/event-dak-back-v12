<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->enum('type', ['video', 'image']);
            $table->string('url', 255);
            $table->boolean('photo_required')->default(1);
            $table->boolean('attendy_required')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_uploads');
    }
};
