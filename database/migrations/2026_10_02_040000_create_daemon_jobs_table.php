<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daemon_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('service', 80)->nullable();
            $table->string('state', 30)->default('queued');
            $table->string('phase', 80)->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->json('log')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['machine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daemon_jobs');
    }
};
