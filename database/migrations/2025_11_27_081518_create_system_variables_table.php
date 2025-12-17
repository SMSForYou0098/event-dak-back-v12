<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_variables', function (Blueprint $table) {
            $table->id();
            $table->string('key', 255)->nullable()->default(null);
            $table->text('value')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_variables');
    }
};
