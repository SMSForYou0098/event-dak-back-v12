<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Drop foreign key constraints first
        Schema::table('promo_codes', function (Blueprint $table) {
            if (Schema::hasColumn('promo_codes', 'amusement_booking_id')) {
                $table->dropForeign('promo_codes_amusement_booking_id_foreign');
                $table->dropColumn('amusement_booking_id');
            }
        });

        // Drop the amusement and accreditation tables
        Schema::dropIfExists('amusement_pos_bookings');
        Schema::dropIfExists('amusement_pending_master_bookings');
        Schema::dropIfExists('amusement_pending_bookings');
        Schema::dropIfExists('amusement_master_bookings');
        Schema::dropIfExists('amusement_bookings');
        Schema::dropIfExists('amusement_agent_master_bookings');
        Schema::dropIfExists('amusement_agent_bookings');
        Schema::dropIfExists('accreditation_master_bookings');
        Schema::dropIfExists('accreditation_bookings');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // These tables are being removed permanently, down migration not provided
        // If you need to restore them, use a database backup
    }
};
