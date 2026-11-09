<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People who asked a status page to email them when something
        // happens: a machine's page or a workspace's. Confirmed by clicking
        // the link they're sent; the token is also their unsubscribe link.
        Schema::create('status_subscribers', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable');
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('confirm_sent_at')->nullable();
            $table->timestamps();

            // The generated name is longer than MySQL allows (64), so MySQL
            // and MariaDB get a short one; PostgreSQL and SQLite keep the
            // generated name existing databases already have.
            $table->unique(['subscribable_type', 'subscribable_id', 'email'], in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'status_subscribers_subscriber_unique' : null);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_subscribers');
    }
};
