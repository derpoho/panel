<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pull request previews: an environment made for each pull request on the
 * project's repository, on the machine chosen for previews, running the
 * pull request's code, and removed when it closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // {enabled, repo, machine_id, copy_data, domain, limit, user_id}
            $table->json('previews')->nullable()->after('name');
        });

        Schema::table('project_environments', function (Blueprint $table) {
            $table->unsignedInteger('pull_request')->nullable()->after('source_id');
            $table->string('head', 40)->nullable()->after('pull_request');
        });
    }

    public function down(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->dropColumn(['pull_request', 'head']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('previews');
        });
    }
};
