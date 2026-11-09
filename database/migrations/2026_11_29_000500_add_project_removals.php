<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removing an environment's apps and services can't always happen at once:
 * a machine is offline, or a deploy is still going. The environment stays,
 * hidden, marked as being removed, and is retried until its machines have
 * nothing of it left. A pull request has one preview at most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->timestamp('removing_at')->nullable()->after('refreshed_at');
            $table->unique(['project_id', 'pull_request']);
        });
        Schema::table('project_nodes', function (Blueprint $table) {
            // When its removal from the machine was last asked for.
            $table->timestamp('removing_at')->nullable()->after('data_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_nodes', function (Blueprint $table) {
            $table->dropColumn('removing_at');
        });
        Schema::table('project_environments', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'pull_request']);
            $table->dropColumn('removing_at');
        });
    }
};
