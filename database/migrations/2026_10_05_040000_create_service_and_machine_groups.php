<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Groups of a machine's services, by inventory key (docker:…,
        // systemd:…). A service is in at most one group.
        Schema::create('service_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('services');
            $table->timestamps();
        });

        Schema::create('machine_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestamps();
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('machine_group_id')->nullable()->after('workspace_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_group_id');
        });
        Schema::dropIfExists('machine_groups');
        Schema::dropIfExists('service_groups');
    }
};
