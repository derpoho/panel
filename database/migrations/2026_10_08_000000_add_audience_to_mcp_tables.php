<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP access can be for a package's own MCP server as well as for a
 * workspace: tokens, sign-in codes and the call log say which (audience),
 * and a package's have no workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mcp_tokens', 'mcp_oauth_codes'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (! Schema::hasColumn($name, 'audience')) {
                    $table->string('audience', 16)->default('workspace')->after('id');
                }
                $table->foreignId('workspace_id')->nullable()->change();
            });
        }

        Schema::table('mcp_tool_calls', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->change();
        });

        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->index(['audience', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->dropIndex(['audience', 'user_id']);
        });

        foreach (['mcp_tokens', 'mcp_oauth_codes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('audience');
            });
        }
    }
};
