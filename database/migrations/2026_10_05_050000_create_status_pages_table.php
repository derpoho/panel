<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One per machine: a page listing its services and whether they're
        // up, at /status/{slug}, for anyone or only the workspace.
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 60)->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('public')->default(true);
            $table->string('title', 80)->nullable();
            $table->string('description', 280)->nullable();
            // Per service key: {shown: bool, label: string|null}.
            $table->json('services')->nullable();
            $table->boolean('include_new')->default(true);
            $table->boolean('show_uptime')->default(true);
            $table->string('notice', 500)->nullable();
            $table->string('notice_kind', 20)->nullable();
            // Daily counts for the uptime bars, last 90 days.
            $table->json('uptime')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_pages');
    }
};
