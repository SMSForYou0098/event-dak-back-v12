<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable()->default(null);
            $table->string('title', 255)->nullable()->default(null);
            $table->longText('content')->nullable()->default(null);
            $table->boolean('status')->nullable()->default(null);
            $table->longText('tags')->nullable()->default(null);
            $table->longText('categories')->nullable()->default(null);
            $table->string('meta_keyword', 255)->nullable()->default(null);
            $table->text('meta_description')->nullable()->default(null);
            $table->string('meta_title', 255)->nullable()->default(null);
            $table->string('thumbnail', 255)->nullable()->default(null);
            $table->integer('view_count')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
