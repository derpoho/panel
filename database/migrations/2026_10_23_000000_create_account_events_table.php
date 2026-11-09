<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What happened to one person's account: sign-ins, wrong passwords,
        // password and two-factor changes, devices signed out. Theirs, not a
        // workspace's. The email is copied so a deleted account's rows still
        // say whose they were; a lockout for an address with no account has
        // neither.
        Schema::create('account_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('event', 40);
            // password, passkey, google, github, recovery_code...
            $table->string('method', 20)->nullable();
            $table->json('meta')->nullable();
            // Staff who did it from the admin user page, or the View as
            // session they did it in. The person sees ServerOS Support.
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('staff_name')->nullable();
            $table->foreignId('impersonation_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index(['event', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_events');
    }
};
