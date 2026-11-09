<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maintenance planned ahead: announced on the status page beforehand,
     * started on time, and (with work to do) a restart or updates run by
     * ServerOS, ending early once the machine is back.
     */
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 200)->nullable();
            $table->boolean('public')->default(true);
            // none, reboot or updates (install everything, then restart if needed)
            $table->string('action', 20)->default('none');
            // scheduled, active, done or cancelled
            $table->string('state', 20)->default('scheduled');
            // While active with work: updating, rebooting, then back
            $table->string('phase', 20)->nullable();
            $table->uuid('job_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // How it went, in a sentence: "Back after 2 minutes."
            $table->string('outcome', 300)->nullable();
            $table->timestamps();

            $table->index(['state', 'starts_at']);
            $table->index(['machine_id', 'state']);
        });

        // When the daemon last said hello: a restart is done once it has
        // connected again after the restart was sent.
        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('connected_at');
        });
        Schema::dropIfExists('maintenance_windows');
    }
};
