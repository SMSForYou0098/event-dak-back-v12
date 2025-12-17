<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_users', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
            $table->boolean('status')->nullable()->default(null);
            $table->integer('user_id')->nullable()->default(null);
            $table->integer('agent_id')->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->string('attndies_status', 255)->nullable()->default(null);
            $table->string('Name', 255)->nullable()->default(null);
            $table->string('Email', 255)->nullable()->default(null);
            $table->bigInteger('Mo')->nullable()->default(null);
            $table->string('Gender', 255)->nullable()->default(null);
            $table->string('Photo', 255)->nullable()->default(null);
            $table->string('Photo_ID', 255)->nullable()->default(null);
            $table->text('Company_Name')->nullable()->default(null);
            $table->text('Designation')->nullable()->default(null);
            $table->text('description')->nullable()->default(null);
            $table->longText('hobby')->nullable()->default(null);
            $table->string('photo_id_type', 255)->nullable()->default(null);
            $table->text('hobby_1')->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_users');
    }
};
