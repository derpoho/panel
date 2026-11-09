<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the dashboard lays out its sections: tabs along the top
        // (null, the default) or a sidebar down the left. Chosen in
        // Settings › Appearance.
        Schema::table('users', function (Blueprint $table) {
            $table->string('nav_layout', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('nav_layout'));
    }
};
