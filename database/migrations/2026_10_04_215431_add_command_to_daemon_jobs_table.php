<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            // The command sent to the daemon, encrypted (deploy env holds
            // secrets), so a deploy can be retried exactly as it was sent.
            $table->longText('command')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->dropColumn('command');
        });
    }
};
