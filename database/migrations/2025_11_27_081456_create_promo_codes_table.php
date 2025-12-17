<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable()->default(null);
            $table->string('code', 255);
            $table->text('description')->nullable()->default(null);
            $table->enum('discount_type', ['fixed', 'percentage']);
            $table->decimal('discount_value', 8,2)->nullable()->default(null);
            $table->decimal('minimum_spend', 8,2)->nullable()->default(null);
            $table->integer('usage_limit')->nullable()->default(null);
            $table->text('booking_limit')->nullable()->default(null);
            $table->integer('usage_per_user')->nullable()->default(null);
            $table->boolean('status')->default(1);
            $table->timestamp('start_date')->nullable()->default(null);
            $table->timestamp('end_date')->nullable()->default(null);
            $table->softDeletes();
            $table->integer('remaining_count')->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
