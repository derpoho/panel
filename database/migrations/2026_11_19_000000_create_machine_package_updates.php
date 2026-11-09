<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Machines\PackageUpdates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OS patch management. Each machine keeps the updates its last check
 * found (machine_package_updates) and a summary on the machine itself, so
 * the Updates page can show every machine at once without asking them.
 * Rollouts install chosen packages (the latest, or exact versions) on
 * many machines, all at once or in batches.
 *
 * Machines checked before this get their latest check filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_package_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('arch', 40)->nullable();
            $table->string('installed', 128)->nullable();
            $table->string('candidate', 128);
            $table->boolean('security')->default(false);
            $table->timestamps();
            $table->index(['machine_id', 'name']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('updates_checked_at')->nullable();
            $table->unsignedInteger('updates_count')->default(0);
            $table->unsignedInteger('security_updates_count')->default(0);
            $table->boolean('reboot_required')->default(false);
            $table->json('held_packages')->nullable();
            $table->json('os_release')->nullable();
            // False when the list came from an older daemon's capped lines.
            $table->boolean('updates_complete')->default(true);
        });

        Schema::create('package_rollouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200);
            // [{name, version|null}]; empty means every waiting update.
            $table->json('packages');
            $table->string('mode', 10)->default('latest');
            $table->boolean('allow_downgrade')->default(false);
            $table->unsignedInteger('batch_size')->nullable();
            $table->boolean('stop_on_failure')->default(true);
            $table->string('reboot', 12)->default('never');
            $table->timestamp('scheduled_for')->nullable();
            $table->string('state', 12)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'state']);
            $table->index('state');
        });

        Schema::create('package_rollout_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_rollout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->uuid('job_id')->nullable();
            $table->uuid('reboot_job_id')->nullable();
            $table->string('state', 12)->default('pending');
            $table->text('outcome')->nullable();
            $table->json('installed')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['package_rollout_id', 'state']);
        });

        // What each machine's last check found, from its latest check.
        Machine::query()->whereNotNull('uid')->select('id')->chunkById(200, function ($machines) {
            foreach ($machines as $machine) {
                $job = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'package_updates')
                    ->where('state', 'succeeded')->latest()->limit(5)->get()
                    ->first(fn (DaemonJob $job) => ! (bool) ($job->result['applied'] ?? false));
                if ($job !== null) {
                    rescue(fn () => PackageUpdates::ingest($job, followUp: false), report: false);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_rollout_machines');
        Schema::dropIfExists('package_rollouts');
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['updates_checked_at', 'updates_count', 'security_updates_count', 'reboot_required', 'held_packages', 'os_release', 'updates_complete']);
        });
        Schema::dropIfExists('machine_package_updates');
    }
};
