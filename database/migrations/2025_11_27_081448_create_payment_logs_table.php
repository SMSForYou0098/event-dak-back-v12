<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->nullable()->default(null);
            $table->string('phone', 255)->nullable()->default(null);
            $table->text('session_id')->nullable()->default(null);
            $table->string('payment_id', 255)->nullable()->default(null);
            $table->string('txnid', 255)->nullable()->default(null);
            $table->string('status', 255);
            $table->string('mode', 255)->nullable()->default(null);
            $table->timestamp('addedon')->nullable()->default(null);
            $table->decimal('amount', 10,2);
            $table->longText('params')->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
