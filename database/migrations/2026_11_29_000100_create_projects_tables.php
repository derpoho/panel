<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects: an app and the resources it uses (a database, a cache, object
 * storage, websockets), which may sit on different machines, joined by a
 * private WireGuard network of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // The private network's name on each machine (sos<uid>).
            $table->string('uid', 12)->unique();
            $table->string('name', 80);
            $table->timestamps();
        });

        // Each machine a project's parts run on, with its place on the network.
        Schema::create('project_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('host');
            $table->unsignedSmallInteger('listen_port');
            $table->string('public_key', 64)->nullable();
            // pending, up, failed, offline
            $table->string('state', 16)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'machine_id']);
            $table->unique(['project_id', 'host']);
        });

        // The apps and resources on the canvas: a deploy on a machine.
        Schema::create('project_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('name', 63);
            // app, database, cache, storage, websockets
            $table->string('kind', 16);
            $table->string('template', 63)->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'machine_id', 'name']);
        });

        // An app using a resource: its connection settings go in the app's env.
        Schema::create('project_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_node_id')->constrained('project_nodes')->cascadeOnDelete();
            $table->foreignId('resource_node_id')->constrained('project_nodes')->cascadeOnDelete();
            // The setting names written into the app, so removing the link
            // takes exactly those out again.
            $table->json('env')->nullable();
            // pending, applied, failed
            $table->string('state', 16)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['app_node_id', 'resource_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_links');
        Schema::dropIfExists('project_nodes');
        Schema::dropIfExists('project_machines');
        Schema::dropIfExists('projects');
    }
};
