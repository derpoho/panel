<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a self-hosted install finished its web installer (App\Support\Install):
 * until then every request goes to the installer. An install that already
 * has accounts was set up before the installer existed, so it counts as set
 * up from now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('install_identity', function (Blueprint $table) {
            $table->timestamp('set_up_at')->nullable();
        });

        if (DB::table('users')->exists()) {
            DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('install_identity', function (Blueprint $table) {
            $table->dropColumn('set_up_at');
        });
    }
};
