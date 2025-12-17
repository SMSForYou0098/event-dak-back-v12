<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL to alter the column type with casting, which is required for PostgreSQL
        // when converting string to integer if there's existing data.
        DB::statement('ALTER TABLE pos_bookings ALTER COLUMN quantity TYPE INTEGER USING quantity::INTEGER');

        // Also update the default value if needed, though usually handled by schema builder
        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->string('quantity', 255)->nullable()->default(null)->change();
        });
    }
};
