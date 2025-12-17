<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_configs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 255)->nullable()->default(null);
            $table->string('item_id', 255)->nullable()->default(null);
            $table->text('category_name')->nullable()->default(null);
            $table->text('meta_title')->nullable()->default(null);
            $table->text('meta_tag')->nullable()->default(null);
            $table->text('meta_description')->nullable()->default(null);
            $table->text('meta_keyword')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_configs');
    }
};
