<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff actions can be about more than a user or a workspace (a doc page,
     * a partner, a failed job, or nothing at all), and keep the names of who
     * did it and what it was about, so they still read after either is gone.
     */
    public function up(): void
    {
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->string('target_type', 30)->nullable()->change();
            $table->unsignedBigInteger('target_id')->nullable()->change();
            $table->string('target_label', 160)->nullable()->after('target_id');
            $table->string('admin_name')->nullable()->after('admin_id');
            $table->string('admin_email')->nullable()->after('admin_name');
            $table->index('action');
            $table->index('created_at');
        });

        DB::table('admin_actions')->orderBy('id')->each(function (object $row) {
            $admin = DB::table('users')->where('id', $row->admin_id)->first(['name', 'email']);
            $label = match ($row->target_type) {
                'user' => DB::table('users')->where('id', $row->target_id)->value('name'),
                'workspace' => DB::table('workspaces')->where('id', $row->target_id)->value('name'),
                default => null,
            };

            DB::table('admin_actions')->where('id', $row->id)->update([
                'admin_name' => $admin->name ?? null,
                'admin_email' => $admin->email ?? null,
                'target_label' => $label === null ? null : mb_substr($label, 0, 160),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('admin_actions', function (Blueprint $table) {
            $table->dropIndex(['action']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['target_label', 'admin_name', 'admin_email']);
        });
    }
};
