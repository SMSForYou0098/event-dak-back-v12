<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('ip_address', 255)->nullable()->default(null);
            $table->string('event_id', 255)->nullable()->default(null);
            $table->string('session_id', 255)->nullable()->default(null);
            $table->longText('user_agent')->nullable()->default(null);
            $table->longText('url')->nullable()->default(null);
            $table->longText('domain')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ip_addresses');
    }
};
