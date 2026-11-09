<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server fixes: the agent reads a machine's logs and state, explains what's
 * wrong and proposes steps from a short allowlist; a person approves them
 * and ServerOS runs them as ordinary jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_fixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service_key')->nullable();
            $table->string('service_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 40)->default('manual');
            $table->text('note')->nullable();
            // investigating, needs_approval, nothing_to_do, running, fixed, failed, declined, error
            $table->string('state', 20)->default('investigating')->index();
            $table->string('title', 160)->nullable();
            $table->text('cause')->nullable();
            $table->text('explanation')->nullable();
            $table->string('confidence', 10)->nullable();
            $table->json('evidence')->nullable();
            $table->json('actions')->nullable();
            $table->json('results')->nullable();
            $table->string('model', 80)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->foreignId('agent_fix_id')->nullable()->after('workflow_node')->constrained('agent_fixes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_fix_id');
        });
        Schema::dropIfExists('agent_fixes');
    }
};
