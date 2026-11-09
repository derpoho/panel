<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Machine usage history for the machine page's charts: one row per
        // minute, holding the minute's average and peak. Kept for 30 days.
        Schema::create('machine_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ts');
            $table->unsignedSmallInteger('samples')->default(0);
            $table->float('cpu')->nullable();
            $table->float('cpu_max')->nullable();
            $table->unsignedBigInteger('mem_used')->nullable();
            $table->unsignedBigInteger('mem_max')->nullable();
            $table->unsignedTinyInteger('disk_percent')->nullable();

            $table->unique(['machine_id', 'ts']);
            $table->index('ts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_samples');
    }
};
