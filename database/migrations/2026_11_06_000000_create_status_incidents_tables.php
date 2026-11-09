<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Incidents written for status pages: what's wrong, in the
        // workspace's words, with updates as it's worked on. On one
        // machine's page (and the workspace's), or the workspace's only.
        Schema::create('status_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            // degraded, partial or major.
            $table->string('impact', 20);
            // investigating, identified, monitoring or resolved: the latest update's.
            $table->string('state', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'resolved_at']);
        });

        Schema::create('status_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_incident_id')->constrained()->cascadeOnDelete();
            $table->string('state', 20);
            $table->text('message');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_incident_updates');
        Schema::dropIfExists('status_incidents');
    }
};
