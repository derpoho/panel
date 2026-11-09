<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Business workspace's own domains, through Cloudflare for SaaS: one for
 * the dashboard (ops.acme.com) and one for its status page, either a
 * hostname of its own (status.acme.com) or a path on the dashboard's
 * (ops.acme.com/status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 12);
            $table->string('hostname')->nullable()->unique();
            $table->string('path', 60)->nullable();
            $table->string('status_page_slug', 60)->nullable();
            $table->string('cloudflare_id', 64)->nullable();
            $table->string('state', 12)->default('pending');
            $table->string('ssl_state', 40)->nullable();
            $table->json('verification')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'purpose']);
            $table->index(['state', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_domains');
    }
};
