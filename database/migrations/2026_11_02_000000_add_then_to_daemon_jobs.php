<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A job to queue once this one succeeds, as the same person:
        // {kind, job, service}. Removing a container ServerOS only found
        // adopts it first, then removes it.
        Schema::table('daemon_jobs', fn (Blueprint $table) => $table->json('then')->nullable());
    }

    public function down(): void
    {
        Schema::table('daemon_jobs', fn (Blueprint $table) => $table->dropColumn('then'));
    }
};
