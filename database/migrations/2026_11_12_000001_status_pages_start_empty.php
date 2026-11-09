<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New status pages start empty: services found on a machine only show once
 * someone picks them. Pages that exist keep the setting they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->boolean('include_new')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->boolean('include_new')->default(true)->change();
        });
    }
};
