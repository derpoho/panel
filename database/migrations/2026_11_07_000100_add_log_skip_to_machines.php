<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Services and line patterns whose logs never leave the machine.
        // Set from the panel; the daemon reports its own on every connect
        // (they can be set in daemon.toml too).
        Schema::table('machines', fn (Blueprint $table) => $table->json('log_skip')->nullable());
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('log_skip'));
    }
};
