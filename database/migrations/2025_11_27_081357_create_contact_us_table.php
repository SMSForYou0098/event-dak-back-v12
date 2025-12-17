<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_us', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable()->default(null);
            $table->string('email', 255)->nullable()->default(null);
            $table->string('number', 255)->nullable()->default(null);
            $table->text('address')->nullable()->default(null);
            $table->string('query', 255)->nullable()->default(null);
            $table->longText('message')->nullable()->default(null);
            $table->longText('image')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_us');
    }
};
