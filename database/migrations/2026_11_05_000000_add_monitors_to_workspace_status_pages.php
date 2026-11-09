<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_status_pages', function (Blueprint $table) {
            // Uptime monitors on no machine that the page shows, by id.
            $table->json('monitors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_status_pages', function (Blueprint $table) {
            $table->dropColumn('monitors');
        });
    }
};
