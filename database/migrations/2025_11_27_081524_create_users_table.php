<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable()->default(null);
            $table->string('email', 255)->nullable()->default(null);
            $table->longText('photo')->nullable()->default(null);
            $table->longText('doc')->nullable()->default(null);
            $table->text('company_name')->nullable()->default(null);
            $table->boolean('agent_disc')->nullable()->default(null);
            $table->string('bank_micr', 255)->nullable()->default(null);
            $table->string('bank_branch', 255)->nullable()->default(null);
            $table->string('bank_ifsc', 255)->nullable()->default(null);
            $table->string('bank_number', 255)->nullable()->default(null);
            $table->string('bank_name', 255)->nullable()->default(null);
            $table->string('city', 255)->nullable()->default(null);
            $table->string('state', 255)->nullable()->default(null);
            $table->string('pincode', 255)->nullable()->default(null);
            $table->text('address')->nullable()->default(null);
            $table->string('tax_number', 30)->nullable()->default(null);
            $table->string('alt_number', 255)->nullable()->default(null);
            $table->string('organisation', 255)->nullable()->default(null);
            $table->string('brand_name', 255)->nullable()->default(null);
            $table->longText('thumbnail')->nullable()->default(null);
            $table->bigInteger('number')->nullable()->default(null);
            $table->longText('reporting_user')->nullable()->default(null);
            $table->timestamp('email_verified_at')->nullable()->default(null);
            $table->string('password', 255);
            $table->string('sms', 10)->nullable()->default('default');
            $table->integer('status');
            $table->string('remember_token', 100)->nullable()->default(null);
            $table->integer('qr_length')->nullable()->default(8);
            $table->boolean('authentication')->nullable()->default(0);
            $table->boolean('agreement_status')->nullable()->default(null);
            $table->string('payment_method', 255)->nullable()->default(null);
            $table->string('org_type_of_company', 255)->nullable()->default(null);
            $table->longText('org_office_address')->nullable()->default(null);
            $table->string('org_signature_type', 255)->nullable()->default(null);
            $table->string('org_name_signatory', 255)->nullable()->default(null);
            $table->longText('org_signatory_image')->nullable()->default(null);
            $table->string('org_gst_no', 255)->nullable()->default(null);
            $table->string('pan_no', 255)->nullable()->default(null);
            $table->string('account_holder', 255)->nullable()->default(null);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
