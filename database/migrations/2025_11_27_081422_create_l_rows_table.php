<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('l_rows', function (Blueprint $table) {
            $table->id();
            $table->string('section_id', 255)->nullable()->default(null);
            $table->string('label', 255)->nullable()->default(null);
            $table->integer('seats')->nullable()->default(null);
            $table->integer('capacity')->nullable()->default(null);
            $table->boolean('is_blocked')->nullable()->default(null);
            $table->string('row_shape', 255)->nullable()->default(null);
            $table->string('curve_amount', 255)->nullable()->default(null);
            $table->string('spacing', 255)->nullable()->default(null);
            $table->string('ticket_id', 255)->nullable()->default(null);
            $table->integer('display_order')->nullable()->default(null);
            $table->longText('meta_data')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l_rows');
    }
};
