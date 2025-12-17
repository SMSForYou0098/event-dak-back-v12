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
        // Fix all numeric columns that were incorrectly defined as strings
        // Use raw SQL with USING clause to safely cast existing data

        DB::statement('ALTER TABLE pos_bookings ALTER COLUMN price TYPE DECIMAL(10,2) USING COALESCE(NULLIF(price, \'\')::DECIMAL, 0)');
        DB::statement('ALTER TABLE pos_bookings ALTER COLUMN amount TYPE DECIMAL(10,2) USING COALESCE(NULLIF(amount, \'\')::DECIMAL, 0)');
        DB::statement('ALTER TABLE pos_bookings ALTER COLUMN total_amount TYPE DECIMAL(10,2) USING COALESCE(NULLIF(total_amount, \'\')::DECIMAL, 0)');
        DB::statement('ALTER TABLE pos_bookings ALTER COLUMN total_tax TYPE DECIMAL(10,2) USING COALESCE(NULLIF(total_tax, \'\')::DECIMAL, 0)');

        // Update defaults via schema builder
        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->change();
            $table->decimal('amount', 10, 2)->nullable()->default(0)->change();
            $table->decimal('total_amount', 10, 2)->nullable()->default(0)->change();
            $table->decimal('total_tax', 10, 2)->nullable()->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_bookings', function (Blueprint $table) {
            $table->string('price', 255)->change();
            $table->string('amount', 255)->nullable()->default(null)->change();
            $table->string('total_amount', 255)->nullable()->default(null)->change();
            $table->string('total_tax', 255)->nullable()->default(null)->change();
        });
    }
};
