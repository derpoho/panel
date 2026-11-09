<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_status_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            // Shares /status/{slug} with machine pages; unique across both.
            $table->string('slug', 60)->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('public')->default(true);
            $table->string('title', 80)->nullable();
            $table->string('description', 280)->nullable();
            $table->text('notice')->nullable();
            $table->string('notice_kind', 16)->nullable();
            $table->boolean('show_uptime')->default(true);
            // Machine ids to show, or null for every machine in the workspace.
            $table->json('machines')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_status_pages');
    }
};
