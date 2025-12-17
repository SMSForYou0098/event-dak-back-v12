<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('blog_id', 255)->nullable()->default(null);
            $table->longText('comment')->nullable()->default(null);
            $table->string('replier_id', 255)->nullable()->default(null);
            $table->longText('replirs')->nullable()->default(null);
            $table->longText('likes')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comments');
    }
};
