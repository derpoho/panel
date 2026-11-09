<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Service log lines the daemons ship, kept for the plan's retention
        // and searched from the Logs page.
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            // The service's discovery key (docker:<short id>, systemd:<unit>)
            // and its name, which outlives a redeploy's new container id.
            $table->string('service_key', 300);
            $table->string('service', 200);
            // When the service wrote it, unix milliseconds.
            $table->unsignedBigInteger('logged_at');
            // error, warn, info or debug.
            $table->string('level', 5);
            // stdout, stderr or journal.
            $table->string('stream', 7);
            $table->text('message');

            $table->index(['machine_id', 'id']);
            $table->index(['machine_id', 'service', 'id']);
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
