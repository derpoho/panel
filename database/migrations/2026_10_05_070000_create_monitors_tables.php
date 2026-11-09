<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uptime monitors: a URL or a host and port, checked from the panel
        // on a schedule, with incidents and alerts when it goes down.
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            // http or tcp.
            $table->string('type', 10);
            $table->string('url', 2048)->nullable();
            $table->string('host', 255)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('timeout_seconds')->default(10);
            // Null means any 2xx or 3xx; otherwise one exact status code.
            $table->unsignedSmallInteger('expected_status')->nullable();
            $table->string('keyword', 200)->nullable();
            $table->boolean('paused')->default(false);
            $table->boolean('alerts')->default(true);
            // pending, up or down.
            $table->string('status', 10)->default('pending');
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->timestamp('ssl_alerted_at')->nullable();
            // Per day: [checks, ok, total response ms], last 90 days.
            $table->json('daily')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error', 300)->nullable();

            $table->index(['monitor_id', 'checked_at']);
        });

        Schema::create('monitor_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('cause', 300);

            $table->index(['monitor_id', 'started_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('alert_email')->default(true);
            $table->text('alert_webhook_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['alert_email', 'alert_webhook_url']);
        });
        Schema::dropIfExists('monitor_incidents');
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitors');
    }
};
