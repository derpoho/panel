<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ServerOS GitHub App installed on someone's account or
        // organisation, connected to a workspace: what lets it read their
        // private repositories. Tokens are minted per deploy, never kept.
        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('installation_id');
            $table->string('account_login');
            $table->string('account_type')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'installation_id']);
            $table->index('account_login');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_installations');
    }
};
