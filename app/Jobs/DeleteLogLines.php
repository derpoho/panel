<?php

namespace App\Jobs;

use App\Support\Logs\LogDeletion;
use App\Support\Logs\LogStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A big delete from the Logs page, in the background. Safe to run again:
 * it deletes whatever of the scope is still there. It carries the
 * workspace, not a connection: a workspace's own store is registered as
 * the job runs.
 *
 * @phpstan-import-type Scope from LogDeletion
 */
class DeleteLogLines implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /**
     * @param  list<int>  $machineIds
     * @param  Scope  $scope
     */
    public function __construct(
        public int $workspaceId,
        public array $machineIds,
        public array $scope,
        public int $upTo,
    ) {}

    public function handle(): void
    {
        LogDeletion::run($this->machineIds, $this->scope, $this->upTo, LogStore::connection($this->workspaceId));
    }
}
