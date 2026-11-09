<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the one "did the install get stuck?" email went (onboarding:connect-nudge).
        Schema::table('workspaces', fn (Blueprint $table) => $table->timestamp('connect_nudge_sent_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('connect_nudge_sent_at'));
    }
};
