<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The kinds of alert the workspace has switched off (AlertKinds), e.g.
        // ["services"]. Empty: everything is on.
        Schema::table('workspaces', fn (Blueprint $table) => $table->json('alert_muted')->nullable());
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('alert_muted'));
    }
};
