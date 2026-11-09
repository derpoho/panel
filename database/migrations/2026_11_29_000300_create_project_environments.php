<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Environments in a project: Production, and copies of it (Staging, a
 * test one) on whichever machines you pick, each with its own apps and
 * services. A copy remembers what it was copied from, so its databases
 * can be filled from the original's data, once or on a schedule, and its
 * apps' code promoted back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('position')->default(0);
            // The environment this one was copied from, where its data comes from.
            $table->foreignId('source_id')->nullable()->constrained('project_environments')->nullOnDelete();
            // How often its data is copied again from the source: null, daily or weekly.
            $table->string('refresh', 8)->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::table('project_nodes', function (Blueprint $table) {
            $table->foreignId('environment_id')->nullable()->after('project_id')->constrained('project_environments')->cascadeOnDelete();
            // The node this one is a copy of, in the environment it came from.
            $table->foreignId('origin_id')->nullable()->after('template')->constrained('project_nodes')->nullOnDelete();
            // Copying a database's data from its origin: waiting, copying, copied, failed.
            $table->string('data_state', 12)->nullable()->after('origin_id');
            $table->string('data_error', 500)->nullable()->after('data_state');
            $table->uuid('data_job_id')->nullable()->after('data_error');
            $table->timestamp('data_at')->nullable()->after('data_job_id');
        });

        // Every project so far is its Production.
        foreach (DB::table('projects')->pluck('id') as $projectId) {
            $id = DB::table('project_environments')->insertGetId([
                'project_id' => $projectId,
                'name' => 'Production',
                'position' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('project_nodes')->where('project_id', $projectId)->update(['environment_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('project_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_id');
            $table->dropConstrainedForeignId('environment_id');
            $table->dropColumn(['data_state', 'data_error', 'data_job_id', 'data_at']);
        });
        Schema::dropIfExists('project_environments');
    }
};
