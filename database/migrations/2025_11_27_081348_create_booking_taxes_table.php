<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->string('type', 255)->nullable()->default(null);
            $table->longText('base_amount')->nullable()->default(null);
            $table->longText('central_gst')->nullable()->default(null);
            $table->longText('state_gst')->nullable()->default(null);
            $table->longText('total_tax')->nullable()->default(null);
            $table->longText('convenience_fee')->nullable()->default(null);
            $table->longText('final_amount')->nullable()->default(null);
            $table->longText('total_final_amount')->nullable()->default(null);
            $table->longText('total_base_amount')->nullable()->default(null);
            $table->longText('total_central_GST')->nullable()->default(null);
            $table->longText('total_state_GST')->nullable()->default(null);
            $table->longText('total_tax_total')->nullable()->default(null);
            $table->longText('total_convenience_fee')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_taxes');
    }
};
