<?php

namespace App\Console\Commands;

use App\Models\WorkspaceLogStore;
use App\Support\Logs\LogBuffer;
use App\Support\Logs\LogIngest;
use App\Support\Logs\LogStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Delivers lines buffered while a workspace's own store was unreachable.
 * A store that fails again keeps the rest buffered for the next run.
 */
class FlushLogBuffers extends Command
{
    protected $signature = 'logs:flush-buffers';

    protected $description = 'Write log lines buffered for unreachable workspace log stores to those stores';

    public function handle(): int
    {
        $delivered = 0;

        WorkspaceLogStore::query()->where('enabled', true)->each(function (WorkspaceLogStore $store) use (&$delivered): void {
            $workspaceId = $store->workspace_id;
            if (LogBuffer::size($workspaceId) === 0) {
                return;
            }

            $connection = LogStore::connection($workspaceId);
            while (($rows = LogBuffer::take($workspaceId, LogIngest::CHUNK)) !== []) {
                try {
                    LogIngest::write($connection, $rows);
                } catch (Throwable $e) {
                    LogBuffer::restore($workspaceId, $rows);
                    LogIngest::failed($workspaceId, $e);
                    $this->warn("Workspace {$workspaceId}: ".LogStore::reason($e));

                    return;
                }
                $delivered += count($rows);
            }
            LogIngest::answered($workspaceId);
        });

        $this->info("Delivered {$delivered} buffered log lines.");

        return self::SUCCESS;
    }
}
