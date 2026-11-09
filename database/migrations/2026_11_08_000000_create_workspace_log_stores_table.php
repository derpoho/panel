<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A workspace's own Postgres for its logs (Business): once enabled,
        // its lines are written there and read from there, never ours.
        Schema::create('workspace_log_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            // pgsql; sqlite only in tests.
            $table->string('driver', 10)->default('pgsql');
            $table->string('host', 255);
            $table->unsignedInteger('port')->default(5432);
            $table->string('database', 120);
            $table->string('username', 120);
            $table->text('password');
            // Lines go here only when enabled, after a test that passed.
            $table->boolean('enabled')->default(false);
            // Prune at the plan's retention, or leave it to them.
            $table->boolean('prune')->default(true);
            // ok or unreachable, with the last error and when it last worked.
            $table->string('status', 12)->default('ok');
            $table->text('last_error')->nullable();
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_log_stores');
    }
};
