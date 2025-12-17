<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cat_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('category_id', 255)->nullable()->default(null);
            $table->longText('qr_code')->nullable()->default(null);
            $table->longText('user_photo')->nullable()->default(null);
            $table->longText('text_1')->nullable()->default(null);
            $table->longText('text_2')->nullable()->default(null);
            $table->longText('text_3')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cat_layouts');
    }
};
