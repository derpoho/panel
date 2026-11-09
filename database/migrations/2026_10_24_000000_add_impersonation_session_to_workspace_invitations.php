<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            // Set when staff sent it while viewing as someone: the invite
            // says ServerOS Support, not the person they were viewing as.
            $table->foreignId('impersonation_session_id')->nullable()->after('invited_by')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonation_session_id');
        });
    }
};
