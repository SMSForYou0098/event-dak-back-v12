<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_configs', function (Blueprint $table) {
            $table->id();
            $table->string('mail_driver', 255)->nullable()->default(null);
            $table->string('mail_host', 255)->nullable()->default(null);
            $table->string('mail_port', 255)->nullable()->default(null);
            $table->string('mail_username', 255)->nullable()->default(null);
            $table->string('mail_password', 255)->nullable()->default(null);
            $table->string('mail_encryption', 255)->nullable()->default(null);
            $table->string('mail_from_address', 255)->nullable()->default(null);
            $table->string('mail_from_name', 255)->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_configs');
    }
};
