<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the IP is, as Cloudflare saw it (CF-IPCountry): a two-letter
        // country code, or T1 for Tor. Null when it didn't come through
        // Cloudflare, or for older rows.
        Schema::table('account_events', fn (Blueprint $table) => $table->string('country', 2)->nullable()->after('ip'));
    }

    public function down(): void
    {
        Schema::table('account_events', fn (Blueprint $table) => $table->dropColumn('country'));
    }
};
