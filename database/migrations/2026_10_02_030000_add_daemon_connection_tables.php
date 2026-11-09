<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->uuid('uid')->nullable()->unique();
            $table->string('certificate_serial')->nullable()->unique();
            $table->string('daemon_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('facts')->nullable();
            $table->json('inventory')->nullable();
            $table->timestamp('inventory_scanned_at')->nullable();
        });

        Schema::create('daemon_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daemon_enrolments');
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['uid', 'certificate_serial', 'daemon_version', 'last_seen_at', 'disconnected_at', 'facts', 'inventory', 'inventory_scanned_at']);
        });
    }
};
