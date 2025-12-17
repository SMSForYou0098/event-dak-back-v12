<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('highlight_events', function (Blueprint $table) {
            $table->id();
            $table->string('sr_no', 255)->nullable()->default(null);
            $table->text('category')->nullable()->default(null);
            $table->string('title', 255)->nullable()->default(null);
            $table->longText('description')->nullable()->default(null);
            $table->longText('sub_description')->nullable()->default(null);
            $table->string('button_link', 255)->nullable()->default(null);
            $table->string('button_text', 255)->nullable()->default(null);
            $table->boolean('external_url')->nullable()->default(null);
            $table->text('images')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('highlight_events');
    }
};
