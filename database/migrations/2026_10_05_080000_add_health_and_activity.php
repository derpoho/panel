<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Per mount from the latest telemetry: [{mount, used, total}].
            $table->json('disks')->nullable();
            // The fullest disk, for sorting and alerts.
            $table->unsignedTinyInteger('disk_percent')->nullable();
            // What has been alerted and not yet cleared: {offline: ts, disk: ts}.
            $table->json('alert_state')->nullable();
        });

        // Who did what in a workspace, and what happened on its own
        // (a server going offline, a monitor going down).
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            // What it was done to, as people read it ("umami", "Shop").
            $table->string('subject', 200)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['disks', 'disk_percent', 'alert_state']);
        });
    }
};
