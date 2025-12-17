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
        Schema::table('content_masters', function (Blueprint $table) {
            $table->enum('type', ['note', 'description'])->default('note')->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_masters', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
