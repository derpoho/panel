<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('plan')->nullable()->after('slug');
            $table->string('billing_interval')->nullable()->after('plan');
            $table->timestamp('trial_ends_at')->nullable()->after('billing_interval');
        });

        // Give existing workspaces the same trial new ones get.
        DB::table('workspaces')->whereNull('plan')->update(['trial_ends_at' => now()->addDays(14)]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['plan', 'billing_interval', 'trial_ends_at']);
        });
    }
};
