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
        Schema::create('user_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('agreement_id')->constrained('agreements')->onDelete('cascade');
            $table->text('content')->nullable(); // Personalized content with replaced URL
            $table->string('status')->default('pending'); // pending, signed, expired
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            
            // Ensure one agreement per user per template
            $table->unique(['user_id', 'agreement_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_agreements');
    }
};
