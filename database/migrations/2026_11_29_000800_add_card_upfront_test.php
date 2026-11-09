<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which half of a signup test a new workspace is in, and when its owner
 * added a card to start the trial. Older workspaces have neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('signup_variant', 16)->nullable()->after('trial_last_ends_at')->index();
            $table->timestamp('card_added_at')->nullable()->after('signup_variant');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['signup_variant']);
            $table->dropColumn(['signup_variant', 'card_added_at']);
        });
    }
};
