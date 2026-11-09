<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet status page, arranged: machine groups whose machines it shows
 * (so ones added to a group join on their own), how a machine with no page
 * of its own shows (one row, or its running services), and a layout of
 * its own sections, headings and order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_status_pages', function (Blueprint $table) {
            $table->json('machine_groups')->nullable();
            $table->string('new_machines', 16)->default('machine');
            $table->json('layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_status_pages', function (Blueprint $table) {
            $table->dropColumn(['machine_groups', 'new_machines', 'layout']);
        });
    }
};
