<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * This install's random id, made once and kept in the database (not a
 * file), so rebuilding the containers keeps it. A self-hosted install's
 * daily install ping sends it, and nothing that says who or where the
 * install is (App\Support\InstallPing). One row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('install_identity', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('install_id')->unique();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('install_identity')->insert(['id' => 1, 'install_id' => (string) Str::uuid(), 'created_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('install_identity');
    }
};
