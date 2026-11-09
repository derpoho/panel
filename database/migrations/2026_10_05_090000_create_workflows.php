<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workflows: a trigger and steps on a canvas (deploy, restart, check,
        // wait, alert...), run by ServerOS when the trigger fires.
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Whose permissions its steps run with: whoever saved it last.
            $table->foreignId('run_as')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('description', 280)->nullable();
            $table->boolean('enabled')->default(false);
            // Copied from the trigger step so events find it quickly.
            $table->string('trigger', 40)->default('manual');
            // {nodes: [...], edges: [...]}, as the editor draws it.
            $table->json('graph');
            // Incoming webhook: a hash to look it up, the token to show again.
            $table->string('webhook_hash', 64)->nullable()->unique();
            $table->text('webhook_token')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'trigger']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // running, succeeded, failed or cancelled.
            $table->string('state', 12)->default('running');
            // {kind, summary, data}: what started it.
            $table->json('trigger');
            // The graph as it was when the run started.
            $table->json('graph');
            // Per step: {state, startedAt, finishedAt, message, port, jobs, until}.
            $table->json('steps');
            // Steps ready to run.
            $table->json('frontier');
            $table->json('logs');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('wake_at')->nullable()->index();

            $table->index(['workflow_id', 'id']);
            $table->index('state');
        });

        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workflow_node', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daemon_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_run_id');
            $table->dropColumn('workflow_node');
        });
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflows');
    }
};
