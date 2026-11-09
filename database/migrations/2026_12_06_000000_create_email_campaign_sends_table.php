<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who a one-off email (an announcement) went to, so sending it again
        // never repeats anyone.
        Schema::create('email_campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->string('campaign', 40);
            $table->string('email');
            $table->timestamp('sent_at');
            $table->unique(['campaign', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_sends');
    }
};
