<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Planned work: alerts and event workflows hold off until then.
        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('maintenance_until')->nullable();
            $table->string('maintenance_reason', 200)->nullable();
            // Say so on the machine's status page.
            $table->boolean('maintenance_public')->default(true);
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->timestamp('muted_until')->nullable();
        });

        // Each person's say in what they're emailed, per workspace.
        Schema::table('user_workspace', function (Blueprint $table) {
            // all, problems (no "it's back" mail) or none.
            $table->string('alert_emails', 10)->default('all');
            $table->boolean('weekly_summary')->default(true);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('summary_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('summary_sent_at'));
        Schema::table('user_workspace', fn (Blueprint $table) => $table->dropColumn(['alert_emails', 'weekly_summary']));
        Schema::table('monitors', fn (Blueprint $table) => $table->dropColumn('muted_until'));
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn(['maintenance_until', 'maintenance_reason', 'maintenance_public']));
    }
};
