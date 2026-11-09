<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-service usage history for the service page's charts. Rows
        // older than a day are pruned as new ones arrive.
        Schema::create('service_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('service', 300);
            $table->unsignedBigInteger('ts');
            $table->float('cpu')->nullable();
            $table->unsignedBigInteger('mem_used')->nullable();
            $table->unsignedBigInteger('net_rx')->nullable();
            $table->unsignedBigInteger('net_tx')->nullable();
            $table->unsignedInteger('pids')->nullable();

            $table->index(['machine_id', 'service', 'ts']);
            $table->index(['machine_id', 'ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_samples');
    }
};
