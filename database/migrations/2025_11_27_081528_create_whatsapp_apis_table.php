<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_apis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('title', 255)->nullable()->default(null);
            $table->longText('variables')->nullable()->default(null);
            $table->string('template_name', 255)->nullable()->default(null);
            $table->string('url', 255)->nullable()->default(null);
            $table->boolean('custom')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_apis');
    }
};
