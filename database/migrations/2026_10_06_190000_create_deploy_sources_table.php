<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Code uploaded as a .zip instead of a Git link. Each becomes a small
        // private Git repository the daemon fetches over HTTPS with a secret
        // token, then it's deleted after a day.
        Schema::create('deploy_sources', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received')->default(0);
            $table->string('status', 20)->default('uploading');
            $table->string('commit', 40)->nullable();
            $table->unsignedInteger('files')->nullable();
            $table->json('detection')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_sources');
    }
};
