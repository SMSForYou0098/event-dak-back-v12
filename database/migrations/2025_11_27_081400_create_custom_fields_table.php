<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->integer('sr_no')->nullable()->default(null);
            $table->string('lable', 30)->nullable()->default(null);
            $table->string('field_name', 255)->nullable()->default(null);
            $table->string('field_type', 255)->nullable()->default(null);
            $table->string('field_value', 255);
            $table->boolean('field_required')->nullable()->default(0);
            $table->longText('field_options')->nullable()->default(null);
            $table->boolean('fixed')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
