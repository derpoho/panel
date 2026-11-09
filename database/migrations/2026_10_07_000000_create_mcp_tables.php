<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI apps registered through OAuth dynamic client registration.
        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name', 120);
            $table->json('redirect_uris');
            $table->string('client_uri', 300)->nullable();
            $table->string('software_id', 120)->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();
        });

        // One way into one workspace's MCP server, as one person: an API key
        // they made, or the access an AI app got through OAuth. Only hashes
        // are kept.
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mcp_oauth_client_id')->nullable()->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->string('kind', 10); // key | oauth
            $table->string('name', 120);
            $table->json('scopes');
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            $table->char('refresh_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'revoked_at']);
        });

        // Authorization codes: single use, minutes long, bound to the client,
        // the redirect and a PKCE challenge.
        Schema::create('mcp_oauth_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('mcp_oauth_client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->json('scopes');
            $table->string('resource', 300);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Every tool an agent called, kept for 30 days: what it asked for
        // and how it went.
        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mcp_token_id')->nullable()->constrained('mcp_tokens')->nullOnDelete();
            $table->string('tool', 64);
            $table->json('arguments')->nullable();
            $table->boolean('ok');
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
        Schema::dropIfExists('mcp_oauth_codes');
        Schema::dropIfExists('mcp_tokens');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
