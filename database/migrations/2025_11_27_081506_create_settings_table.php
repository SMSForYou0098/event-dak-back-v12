<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name', 255)->nullable()->default(null);
            $table->string('meta_title', 255)->nullable()->default(null);
            $table->text('meta_tag')->nullable()->default(null);
            $table->text('meta_description')->nullable()->default(null);
            $table->text('logo')->nullable()->default(null);
            $table->text('mo_logo')->nullable()->default(null);
            $table->longText('banners')->nullable()->default(null);
            $table->text('favicon')->nullable()->default(null);
            $table->text('copyright')->nullable()->default(null);
            $table->text('copyright_link')->nullable()->default(null);
            $table->bigInteger('live_user')->nullable()->default(null);
            $table->longText('sponsors_images')->nullable()->default(null);
            $table->longText('pc_sponsors_images')->nullable()->default(null);
            $table->string('footer_logo', 255)->nullable()->default(null);
            $table->text('footer_address')->nullable()->default(null);
            $table->string('footer_contact', 255)->nullable()->default(null);
            $table->string('nav_logo', 255)->nullable()->default(null);
            $table->text('site_credit')->nullable()->default(null);
            $table->boolean('complimentary_attendee_validation')->nullable()->default(null);
            $table->string('auth_logo', 255)->nullable()->default(null);
            $table->string('missed_call_no', 255)->nullable()->default(null);
            $table->string('whatsapp_number', 255)->nullable()->default(null);
            $table->string('footer_email', 255)->nullable()->default(null);
            $table->string('footer_whatsapp_number', 255)->nullable()->default(null);
            $table->text('footer_bg')->nullable()->default(null);
            $table->boolean('notify_req')->nullable()->default(null);
            $table->longText('home_divider')->nullable()->default(null);
            $table->string('home_divider_url', 255)->nullable()->default(null);
            $table->longText('e_signature')->nullable()->default(null);
            $table->longText('agreement_pdf')->nullable()->default(null);
            $table->string('navColor', 255)->nullable()->default(null);
            $table->string('fontColor', 255)->nullable()->default(null);
            $table->string('footer_font_Color', 255)->nullable()->default(null);
            $table->string('home_bg_color', 255)->nullable()->default(null);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
