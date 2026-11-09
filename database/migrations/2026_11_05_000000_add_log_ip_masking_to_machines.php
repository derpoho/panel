<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the machine masks IP addresses in log lines before they leave
        // it: off, partial or hash. Set from the panel; the daemon reports
        // its own on every connect (it can be set in daemon.toml too).
        Schema::table('machines', fn (Blueprint $table) => $table->string('log_ip_masking', 8)->nullable());
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('log_ip_masking'));
    }
};
