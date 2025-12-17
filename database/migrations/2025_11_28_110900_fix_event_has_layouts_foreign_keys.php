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
        // Convert event_id to bigint (foreign key to events.id)
        DB::statement("
            ALTER TABLE event_has_layouts
            ALTER COLUMN event_id TYPE BIGINT
            USING CASE
                WHEN event_id ~ '^[0-9]+$' THEN event_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        // Convert layout_id to bigint (foreign key to layouts.id)
        DB::statement("
            ALTER TABLE event_has_layouts
            ALTER COLUMN layout_id TYPE BIGINT
            USING CASE
                WHEN layout_id ~ '^[0-9]+$' THEN layout_id::BIGINT
                ELSE NULL::BIGINT
            END
        ");

        Schema::table('event_has_layouts', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->default(null)->change();
            $table->unsignedBigInteger('layout_id')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE event_has_layouts ALTER COLUMN event_id TYPE VARCHAR(255) USING COALESCE(event_id::text, '')");
        DB::statement("ALTER TABLE event_has_layouts ALTER COLUMN layout_id TYPE VARCHAR(255) USING COALESCE(layout_id::text, '')");

        Schema::table('event_has_layouts', function (Blueprint $table) {
            $table->string('event_id', 255)->nullable()->default(null)->change();
            $table->string('layout_id', 255)->nullable()->default(null)->change();
        });
    }
};

