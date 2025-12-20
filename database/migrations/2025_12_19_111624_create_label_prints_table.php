<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('label_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('batch_id')->index();
            $table->string('name');
            $table->string('surname');
            $table->string('number')->nullable();
            $table->string('designation')->nullable();
            $table->string('company_name')->nullable();
            $table->string('stall_number')->nullable();
            $table->boolean('status')->default(false); // false = pending, true = printed
            $table->timestamps();

            $table->index(['user_id', 'batch_id']);
            $table->index(['batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_prints');
    }
};
