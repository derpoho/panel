<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When someone's one Pro trial started. Only their first workspace gets
     * one: kept on the person, so deleting a workspace doesn't reset it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_used_at')->nullable();
        });

        // Everyone who already owns a workspace has had theirs.
        DB::table('users')->whereNull('trial_used_at')->update([
            'trial_used_at' => DB::raw('(select min(workspaces.created_at) from workspaces where workspaces.owner_id = users.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_used_at');
        });
    }
};
