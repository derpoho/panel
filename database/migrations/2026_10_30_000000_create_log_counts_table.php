<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many lines each service logged each minute, by level: what the
        // Logs page's chart reads, so it never counts log_entries itself.
        Schema::create('log_counts', function (Blueprint $table) {
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            // Unix milliseconds at the start of the minute, like logged_at.
            $table->bigInteger('minute');
            $table->string('service', 200);
            $table->string('level', 10);
            $table->unsignedInteger('n');

            $table->unique(['machine_id', 'minute', 'service', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_counts');
    }
};
