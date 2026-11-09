<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who made a machine's join command, so the machine joining is
     * credited to them (as ServerOS Support if staff did while viewing
     * as them) rather than to nobody.
     */
    public function up(): void
    {
        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('machine_id')->constrained()->nullOnDelete();
            $table->foreignId('impersonation_session_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daemon_enrolments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonation_session_id');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
