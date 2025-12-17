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
        Schema::table('users', function (Blueprint $table) {
            // Check and drop columns if they exist
            $columnsToRemove = [
                'signing_date',
                'org_signatory',
                'org_signatory_image',
                'org_name_signatory',
                'org_signature_type',
            ];

            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('signing_date')->nullable();
            $table->string('org_signatory')->nullable();
            $table->longText('org_signatory_image')->nullable();
            $table->string('org_name_signatory')->nullable();
            $table->string('org_signature_type')->nullable();
        });
    }
};
