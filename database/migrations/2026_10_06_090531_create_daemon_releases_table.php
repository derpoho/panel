<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An older panel had a table by this name with other columns. Keep it, out of the way, rather than drop it.
        if (Schema::hasTable('daemon_releases')) {
            if (Schema::hasColumns('daemon_releases', ['version', 'arch', 'channel', 'sha256', 'signature', 'min_from', 'notes_url'])) {
                return;
            }
            Schema::rename('daemon_releases', 'daemon_releases_legacy');

            // Postgres keeps the old index and sequence names, which the new
            // table needs; move them along with the table.
            if (DB::getDriverName() === 'pgsql') {
                foreach (DB::select("select indexname from pg_indexes where tablename = 'daemon_releases_legacy' and indexname like 'daemon_releases\\_%'") as $index) {
                    $renamed = 'daemon_releases_legacy_'.substr($index->indexname, strlen('daemon_releases_'));
                    DB::statement("alter index \"{$index->indexname}\" rename to \"{$renamed}\"");
                }
                DB::statement('alter sequence if exists daemon_releases_id_seq rename to daemon_releases_legacy_id_seq');
            }
        }

        Schema::create('daemon_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40);
            $table->string('arch', 16);
            // stable or canary; daemons follow one, canary also takes stable.
            $table->string('channel', 16);
            $table->string('sha256', 64);
            $table->text('signature');
            $table->string('min_from', 40)->nullable();
            $table->string('notes_url')->nullable();
            $table->timestamps();

            $table->unique(['version', 'arch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daemon_releases');
    }
};
