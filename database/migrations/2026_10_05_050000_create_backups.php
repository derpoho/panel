<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a workspace's backups go: an S3-compatible bucket, or one of
        // its machines set up to receive them over SFTP.
        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('type', 16);
            // S3: endpoint, region, bucket, keys, path style. Encrypted.
            $table->text('settings')->nullable();
            // Machine: the receiver, the key senders use, and its host key.
            $table->foreignId('machine_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('private_key')->nullable();
            $table->text('public_key')->nullable();
            $table->string('host_key', 1000)->nullable();
            $table->string('status', 16)->default('ready');
            $table->string('status_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('backup_destination_id')->nullable()->after('machine_group_id')->constrained()->nullOnDelete();
            $table->string('backup_schedule', 16)->default('off')->after('backup_destination_id');
        });

        // One service's backup: a daemon job, and where it ended up.
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('daemon_job_id')->nullable()->index();
            $table->string('service');
            $table->string('service_name');
            $table->string('trigger', 16);
            $table->string('state', 16)->default('queued');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('location', 1000)->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['machine_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
        Schema::table('machines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backup_destination_id');
            $table->dropColumn('backup_schedule');
        });
        Schema::dropIfExists('backup_destinations');
    }
};
