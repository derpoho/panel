<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('ip_address', 45);
            $table->string('provider');
            $table->string('environment');
            $table->unsignedSmallInteger('services_count')->default(0);
            $table->unsignedInteger('memory_used_mb')->nullable();
            $table->unsignedTinyInteger('cpu_percent')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
