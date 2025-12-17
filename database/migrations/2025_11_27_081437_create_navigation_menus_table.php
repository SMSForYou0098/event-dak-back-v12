<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->id();
            $table->integer('sr_no')->nullable()->default(null);
            $table->string('title', 255);
            $table->bigInteger('page_id')->nullable()->default(null);
            $table->integer('menu_group_id')->nullable()->default(null);
            $table->boolean('status')->nullable()->default(0);
            $table->boolean('type')->nullable()->default(null);
            $table->string('external_url', 255)->nullable()->default(null);
            $table->boolean('new_tab')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_menus');
    }
};
