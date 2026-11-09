<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restoring a backup: the snapshot the daemon took for it (kept on the
 * machine as well as sent off), and its latest restore. Existing backups
 * get their snapshot from the job that took them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('snapshot', 80)->nullable()->after('daemon_job_id');
            $table->uuid('restore_job_id')->nullable()->index()->after('error');
            $table->string('restore_state', 16)->nullable()->after('restore_job_id');
            $table->string('restore_error', 1000)->nullable()->after('restore_state');
            $table->timestamp('restored_at')->nullable()->after('restore_error');
        });

        DB::table('backups')->whereNotNull('daemon_job_id')->where('state', 'succeeded')->orderBy('id')
            ->chunkById(200, function ($backups) {
                $results = DB::table('daemon_jobs')->whereIn('id', $backups->pluck('daemon_job_id'))->pluck('result', 'id');
                foreach ($backups as $backup) {
                    $snapshot = json_decode((string) ($results[$backup->daemon_job_id] ?? ''), true)['snapshot'] ?? null;
                    if (is_string($snapshot) && $snapshot !== '') {
                        DB::table('backups')->where('id', $backup->id)->update(['snapshot' => mb_substr($snapshot, 0, 80)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['snapshot', 'restore_job_id', 'restore_state', 'restore_error', 'restored_at']);
        });
    }
};
