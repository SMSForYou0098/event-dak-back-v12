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
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'banners',
                'sponsors_images',
                'pc_sponsors_images',
                'home_divider',
                'home_divider_url',
                'e_signature',
                'agreement_pdf',
                'navColor',
                'fontColor',
                'footer_font_Color',
                'home_bg_color',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->longText('banners')->nullable()->default(null);
            $table->longText('sponsors_images')->nullable()->default(null);
            $table->longText('pc_sponsors_images')->nullable()->default(null);
            $table->longText('home_divider')->nullable()->default(null);
            $table->string('home_divider_url', 255)->nullable()->default(null);
            $table->longText('e_signature')->nullable()->default(null);
            $table->longText('agreement_pdf')->nullable()->default(null);
            $table->string('navColor', 255)->nullable()->default(null);
            $table->string('fontColor', 255)->nullable()->default(null);
            $table->string('footer_font_Color', 255)->nullable()->default(null);
            $table->string('home_bg_color', 255)->nullable()->default(null);
        });
    }
};
