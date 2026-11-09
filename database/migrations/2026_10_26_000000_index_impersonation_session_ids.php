<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres doesn't index a foreign key by itself. Without these, the
     * Audit sessions page, the session filters and every pruned session's
     * "set null" scan the whole of each table.
     */
    private const array TABLES = [
        'impersonation_views',
        'activities',
        'admin_actions',
        'daemon_jobs',
        'workflow_runs',
        'account_events',
        'workspace_invitations',
        'daemon_enrolments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->index('impersonation_session_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['impersonation_session_id']);
            });
        }
    }
};
