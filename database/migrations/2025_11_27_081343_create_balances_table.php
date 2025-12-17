<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->longText('total_credits');
            $table->double('alert_credit')->nullable()->default(null);
            $table->longText('new_credit')->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->longText('remarks')->nullable()->default(null);
            $table->string('payment_type', 255)->nullable()->default(null);
            $table->boolean('status')->nullable()->default(null);
            $table->string('assign_by', 30)->nullable()->default(null);
            $table->string('account_manager_id', 255)->nullable()->default(null);
            $table->string('manual_deduction', 255)->nullable()->default(null);
            $table->string('auto_deduction', 255)->nullable()->default(null);
            $table->string('transaction_id', 255)->nullable()->default(null);
            $table->string('session_id', 255)->nullable()->default(null);
            $table->text('description')->nullable()->default(null);
            $table->string('token', 255)->nullable()->default(null);
            $table->string('booking_id', 255)->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
