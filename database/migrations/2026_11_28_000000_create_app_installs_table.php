<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which one-click app each install from the app installer was, so staff
 * can see what people install. A deploy job alone doesn't say: its
 * payload is encrypted and has no template name in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('daemon_job_id')->nullable()->unique();
            $table->string('template')->index();
            // panel, mcp, or backfill (matched from an older deploy job)
            $table->string('source', 16);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installs');
    }
};
