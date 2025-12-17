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
        Schema::create('organizer_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('signatory_name')->nullable();
            $table->string('signature_type')->nullable(); // draw, type, upload
            $table->text('signature_text')->nullable();
            $table->string('signature_font')->nullable();
            $table->string('signature_font_style')->nullable();
            $table->longText('signature_image')->nullable();
            $table->date('signing_date')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizer_signatures');
    }
};
