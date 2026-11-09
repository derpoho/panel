<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
