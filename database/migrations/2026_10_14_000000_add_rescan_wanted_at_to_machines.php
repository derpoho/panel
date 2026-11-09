<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rescan a job asked for that couldn't be sent then (the machine was
 * between connections, say): it's sent the next time the machine reports
 * in, unless a scan has come in since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('rescan_wanted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('rescan_wanted_at');
        });
    }
};
