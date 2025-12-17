<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('welcome_pop_ups', function (Blueprint $table) {
            $table->id();
            $table->longText('image')->nullable()->default(null);
            $table->longText('sm_image')->nullable()->default(null);
            $table->string('url', 255)->nullable()->default(null);
            $table->string('sm_url', 255)->nullable()->default(null);
            $table->text('text')->nullable()->default(null);
            $table->text('sm_text')->nullable()->default(null);
            $table->boolean('status')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_pop_ups');
    }
};
