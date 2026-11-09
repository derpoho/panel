<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People let in before launch: they use ServerOS while it's still
        // "coming soon" for everyone else. Set from /admin.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('early_access_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('early_access_at'));
    }
};
