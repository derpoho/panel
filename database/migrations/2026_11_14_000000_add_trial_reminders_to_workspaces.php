<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trial-ending emails (trials:remind): when each one went to the
 * workspace's owner, so a rerun never repeats it, and whether someone
 * turned them off from the email's own link.
 *
 * trial_last_ends_at keeps the trial's end date after a plan is chosen
 * (choosing one clears trial_ends_at), so Admin › Stats can tell how many
 * trials ended and how many converted. Workspaces whose plan was chosen
 * before this have no record of it, so theirs is estimated as the day the
 * workspace was made plus the trial's length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('trial_reminded_3d_at')->nullable();
            $table->timestamp('trial_reminded_1d_at')->nullable();
            $table->timestamp('trial_ended_emailed_at')->nullable();
            $table->timestamp('trial_emails_off_at')->nullable();
            $table->timestamp('trial_last_ends_at')->nullable();
        });

        DB::table('workspaces')->whereNotNull('trial_ends_at')->update(['trial_last_ends_at' => DB::raw('trial_ends_at')]);

        DB::table('workspaces')->whereNull('trial_ends_at')->whereNotNull('created_at')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('workspaces')->where('id', $row->id)->update([
                        'trial_last_ends_at' => Carbon::parse($row->created_at)->addDays(Workspace::TRIAL_DAYS),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['trial_reminded_3d_at', 'trial_reminded_1d_at', 'trial_ended_emailed_at', 'trial_emails_off_at', 'trial_last_ends_at']);
        });
    }
};
