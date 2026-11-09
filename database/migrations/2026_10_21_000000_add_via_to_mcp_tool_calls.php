<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The REST API runs the same tools as MCP and logs its calls in the same
 * table: via says which way a call came, and an API call keeps the HTTP
 * status it answered with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_tool_calls', function (Blueprint $table) {
            $table->string('via', 8)->default('mcp')->after('mcp_token_id');
            $table->unsignedSmallInteger('status')->nullable()->after('ok');
            $table->index(['workspace_id', 'via', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_calls', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'via', 'id']);
            $table->dropColumn(['via', 'status']);
        });
    }
};
