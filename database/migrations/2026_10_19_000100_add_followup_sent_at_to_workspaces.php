<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the one "here's what's running" email went (onboarding:follow-up).
        Schema::table('workspaces', fn (Blueprint $table) => $table->timestamp('followup_sent_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('followup_sent_at'));
    }
};
