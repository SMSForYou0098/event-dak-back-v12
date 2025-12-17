<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->nullable()->default(null);
            $table->text('content')->nullable()->default(null);
            $table->boolean('status')->default(1);
            $table->integer('footer_menu_id')->nullable()->default(null);
            $table->string('meta_title', 255)->nullable()->default(null);
            $table->text('meta_tag')->nullable()->default(null);
            $table->text('meta_description')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
