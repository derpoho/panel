<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('onboarding_dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropColumn('pinned_at');
        });
    }
};
