<?php

namespace App\Mcp\Tools\Backup;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Backup;
use App\Support\Backups\BackupRunner;
use RuntimeException;

/**
 * The History tab's Restore: put a service's data back as it was in one
 * of its backups. The machine snapshots the data first, and that snapshot
 * shows as a pre-restore backup, so restoring it undoes this.
 */
final class RestoreBackup extends Tool
{
    public function __construct(private readonly BackupRunner $runner) {}

    public function name(): string
    {
        return 'restore_backup';
    }

    public function title(): string
    {
        return 'Restore a backup';
    }

    public function description(): string
    {
        return 'Restores a service’s data from one of its backups (list_backups gives the ids, and canRestore says which can be), as the Backups page’s Restore does. Whatever the service has now is replaced: rows written, files changed and uploads since that backup are lost, and databases may be briefly unavailable. '
            .'Before restoring, the machine takes a snapshot of the data as it is, which shows in list_backups with trigger pre-restore: restoring that one undoes this. Only the newest '.BackupRunner::KEPT_ON_MACHINE.' backups of each service are still on the machine to restore from; older ones are only at the destination, which isn’t supported yet. '
            .'Tell the person which service, which backup (its time) and that newer data will be replaced, get their go-ahead, then pass confirm set to the service’s name (serviceName) exactly. Runs as a job: pass wait (up to 25 seconds), then follow it with get_job or list_backups (lastRestore).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'backup' => Schema::integer('The backup’s id, from list_backups.', 1),
            'confirm' => Schema::confirm('the service’s name (serviceName in list_backups)'),
            'wait' => Schema::integer('Seconds to wait for the restore to finish before answering (0–25, default 0). It keeps running either way.', 0, McpContext::MAX_WAIT_SECONDS),
        ], ['backup', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'backup' => ['required', 'integer', 'min:1'],
            'confirm' => ['nullable', 'string', 'max:200'],
            'wait' => ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS],
        ]);

        $backup = Backup::query()->with('machine')->find((int) $data['backup']);
        if ($backup === null || $backup->machine->workspace_id !== $context->workspace->id) {
            throw new ToolError("No backup #{$data['backup']} in {$context->workspace->name}. list_backups shows them.");
        }
        if (($data['confirm'] ?? null) !== $backup->service_name) {
            throw new ToolError("This replaces {$backup->service_name}’s data on {$backup->machine->name} with the backup from {$backup->created_at?->format('j M Y, H:i')}. Ask the person first, then pass confirm set to “{$backup->service_name}” exactly.");
        }

        try {
            $job = $this->runner->restore($backup, $context->user);
        } catch (RuntimeException $e) {
            throw new ToolError("Can’t restore it: {$e->getMessage()}");
        }
        $context->record('backup.restore', $backup->service_name, $backup->machine, [
            'from' => $backup->created_at?->format('j M Y, H:i'),
            'undo' => $backup->trigger === 'pre-restore',
        ]);

        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'backup' => BackupView::backup($backup->refresh()),
            'job' => Present::job($job, 10),
            'note' => 'When it finishes, the snapshot taken before it shows in list_backups as a pre-restore backup; restoring that undoes this.',
            'url' => $context->url('/backups'),
        ];
    }
}
