<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace's own AI keys (Anthropic, OpenAI), for the server agent and
 * coding agents to run on its account instead of ServerOS's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->text('key');
            $table->string('hint', 12);
            $table->string('model', 80)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_keys');
    }
};
