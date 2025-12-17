<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l_venues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('name', 255)->nullable()->default(null);
            $table->longText('location')->nullable()->default(null);
            $table->enum('venue_type', ['stadium', 'auditorium', 'theater'])->nullable()->default(null);
            $table->integer('capacity')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l_venues');
    }
};
