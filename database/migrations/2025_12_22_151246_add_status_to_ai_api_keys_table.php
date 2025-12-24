<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_api_keys', function (Blueprint $table) {
            $table->boolean('status')->default(true)->after('apikey');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_api_keys', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
