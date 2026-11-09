<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each MCP or API call gets an id before it runs; the server jobs it
        // starts carry it, and so do the daemon's actions.log lines for them.
        Schema::table('mcp_tool_calls', function (Blueprint $table) {
            $table->uuid('call_id')->nullable()->index();
        });
        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->uuid('mcp_call_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_calls', fn (Blueprint $table) => $table->dropColumn('call_id'));
        Schema::table('daemon_jobs', fn (Blueprint $table) => $table->dropColumn('mcp_call_id'));
    }
};
