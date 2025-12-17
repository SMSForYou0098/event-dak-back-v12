<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catrgoty_has__fields', function (Blueprint $table) {
            $table->id();
            $table->integer('category_id')->nullable()->default(null);
            $table->string('custom_fields_id', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catrgoty_has__fields');
    }
};
