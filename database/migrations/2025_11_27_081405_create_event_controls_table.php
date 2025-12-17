<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_controls', function (Blueprint $table) {
            $table->id();
            $table->boolean('event_id')->nullable()->default(null);
            $table->boolean('scan_detail')->nullable()->default(null);
            $table->boolean('event_feature')->nullable()->default(null);
            $table->enum('status', ['0', '1', '2', '3'])->nullable()->default(null);
            $table->boolean('house_full')->nullable()->default(null);
            $table->boolean('online_att_sug')->nullable()->default(null);
            $table->boolean('offline_att_sug')->nullable()->default(null);
            $table->boolean('multi_scan')->nullable()->default(null);
            $table->boolean('show_on_home')->nullable()->default(null);
            $table->boolean('ticket_system')->nullable()->default(null);
            $table->boolean('booking_by_seat')->nullable()->default(null);
            $table->boolean('online_booking')->nullable()->default(null);
            $table->boolean('agent_booking')->nullable()->default(null);
            $table->boolean('pos_booking')->nullable()->default(null);
            $table->boolean('complimentary_booking')->nullable()->default(null);
            $table->boolean('exhibition_booking')->nullable()->default(null);
            $table->boolean('amusement_booking')->nullable()->default(null);
            $table->boolean('accreditation_booking')->nullable()->default(null);
            $table->boolean('sponsor_booking')->nullable()->default(null);
            $table->boolean('overnight_event')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_controls');
    }
};
