<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Icons people uploaded: the machine's own, and its services'
            // (by ServiceLabels::identity()), as paths on the public disk.
            $table->string('icon_path')->nullable();
            $table->json('service_icons')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['icon_path', 'service_icons']);
        });
    }
};
