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
        // l_stages.layout_id → BIGINT
        DB::statement("
            ALTER TABLE l_stages
            ALTER COLUMN layout_id TYPE BIGINT
            USING CASE
                WHEN layout_id ~ '^[0-9]+$' THEN layout_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('l_stages', function (Blueprint $table) {
            $table->unsignedBigInteger('layout_id')->nullable()->default(null)->change();
        });

        // l_sections.layout_id → BIGINT
        DB::statement("
            ALTER TABLE l_sections
            ALTER COLUMN layout_id TYPE BIGINT
            USING CASE
                WHEN layout_id ~ '^[0-9]+$' THEN layout_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('l_sections', function (Blueprint $table) {
            $table->unsignedBigInteger('layout_id')->nullable()->default(null)->change();
        });

        // l_rows.section_id → BIGINT
        DB::statement("
            ALTER TABLE l_rows
            ALTER COLUMN section_id TYPE BIGINT
            USING CASE
                WHEN section_id ~ '^[0-9]+$' THEN section_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('l_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->default(null)->change();
        });

        // l_seats.section_id → BIGINT
        DB::statement("
            ALTER TABLE l_seats
            ALTER COLUMN section_id TYPE BIGINT
            USING CASE
                WHEN section_id ~ '^[0-9]+$' THEN section_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('l_seats', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->default(null)->change();
        });

        // l_seats.row_id → BIGINT
        DB::statement("
            ALTER TABLE l_seats
            ALTER COLUMN row_id TYPE BIGINT
            USING CASE
                WHEN row_id ~ '^[0-9]+$' THEN row_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('l_seats', function (Blueprint $table) {
            $table->unsignedBigInteger('row_id')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE l_stages ALTER COLUMN layout_id TYPE VARCHAR(255) USING COALESCE(layout_id::text, '')");
        Schema::table('l_stages', function (Blueprint $table) {
            $table->string('layout_id', 255)->nullable()->default(null)->change();
        });

        DB::statement("ALTER TABLE l_sections ALTER COLUMN layout_id TYPE VARCHAR(255) USING COALESCE(layout_id::text, '')");
        Schema::table('l_sections', function (Blueprint $table) {
            $table->string('layout_id', 255)->nullable()->default(null)->change();
        });

        DB::statement("ALTER TABLE l_rows ALTER COLUMN section_id TYPE VARCHAR(255) USING COALESCE(section_id::text, '')");
        Schema::table('l_rows', function (Blueprint $table) {
            $table->string('section_id', 255)->nullable()->default(null)->change();
        });

        DB::statement("ALTER TABLE l_seats ALTER COLUMN section_id TYPE VARCHAR(255) USING COALESCE(section_id::text, '')");
        Schema::table('l_seats', function (Blueprint $table) {
            $table->string('section_id', 255)->nullable()->default(null)->change();
        });

        DB::statement("ALTER TABLE l_seats ALTER COLUMN row_id TYPE VARCHAR(255) USING COALESCE(row_id::text, '')");
        Schema::table('l_seats', function (Blueprint $table) {
            $table->string('row_id', 255)->nullable()->default(null)->change();
        });
    }
};

