<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('type', 255)->nullable()->default(null);
            $table->string('org_id', 255)->nullable()->default(null);
            $table->integer('event_id')->nullable()->default(null);
            $table->string('event_key', 255)->nullable()->default(null);
            $table->string('sr_no', 255)->nullable()->default(null);
            $table->text('category')->nullable()->default(null);
            $table->string('title', 255)->nullable()->default(null);
            $table->longText('description')->nullable()->default(null);
            $table->longText('sub_description')->nullable()->default(null);
            $table->string('button_link', 255)->nullable()->default(null);
            $table->string('button_text', 255)->nullable()->default(null);
            $table->string('btn_action', 255)->nullable()->default(null);
            $table->string('external_url', 255)->nullable()->default(null);
            $table->text('images')->nullable()->default(null);
            $table->text('sm_image')->nullable()->default(null);
            $table->text('md_image')->nullable()->default(null);
            $table->boolean('display_in_popup')->nullable()->default(null);
            $table->string('media_url', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
