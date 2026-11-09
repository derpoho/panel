<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network ports start at 51820, past what a Postgres smallint holds
 * (unsigned isn't a thing there). An integer holds every port.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_machines', function (Blueprint $table) {
            $table->unsignedInteger('listen_port')->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_machines', function (Blueprint $table) {
            $table->unsignedSmallInteger('listen_port')->change();
        });
    }
};
