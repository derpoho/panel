<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // The company behind the workspace, for agreements such as DPAs
            // and SLAs. All optional.
            $table->string('company_legal_name')->nullable();
            $table->string('company_number')->nullable();
            $table->string('company_tax_id')->nullable();
            $table->text('company_address')->nullable();
            $table->string('company_country')->nullable();
            $table->string('company_contact_name')->nullable();
            $table->string('company_contact_title')->nullable();
            $table->string('company_contact_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'company_legal_name', 'company_number', 'company_tax_id', 'company_address',
                'company_country', 'company_contact_name', 'company_contact_title', 'company_contact_email',
            ]);
        });
    }
};
