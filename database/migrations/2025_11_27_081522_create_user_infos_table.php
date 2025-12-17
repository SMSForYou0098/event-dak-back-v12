<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_infos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('device', 255)->nullable()->default(null);
            $table->text('device_id')->nullable()->default(null);
            $table->string('ip_address', 255)->nullable()->default(null);
            $table->string('browser', 255)->nullable()->default(null);
            $table->string('platform', 255)->nullable()->default(null);
            $table->string('locality', 255)->nullable()->default(null);
            $table->string('country', 255)->nullable()->default(null);
            $table->string('city', 255)->nullable()->default(null);
            $table->string('state', 255)->nullable()->default(null);
            $table->decimal('latitude', 10,7)->nullable()->default(null);
            $table->decimal('longitude', 10,7)->nullable()->default(null);
            $table->timestamp('date')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_infos');
    }
};
