<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('footer_menus', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->bigInteger('footer_group_id');
            $table->bigInteger('page_id');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footer_menus');
    }
};
