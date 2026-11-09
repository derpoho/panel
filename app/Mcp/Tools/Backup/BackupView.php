<?php

namespace App\Mcp\Tools\Backup;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\ToolError;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Support\Backups\BackupRunner;
use App\Support\Health\WorkspaceHealth;
use Closure;
use Illuminate\Support\Collection;

/**
 * How backups look to an agent, as the Backups page shows them: each
 * machine's settings, each run, and each destination without its keys.
 * Also finds a destination from what the agent calls it.
 */
final class BackupView
{
    public const STATES = ['queued', 'running', 'succeeded', 'failed', 'skipped'];

    public const TRIGGERS = ['manual', 'scheduled', 'workflow', 'pre-restore', 'pre-deploy'];

    /** The words the History tab uses for each state. */
    private const LABELS = [
        'queued' => 'Queued',
        'running' => 'Running',
        'succeeded' => 'Done',
        'failed' => 'Failed',
        'skipped' => 'Nothing to keep',
    ];

    /**
     * One service's backup, as a row of the History tab.
     *
     * @return array<string, mixed>
     */
    public static function backup(Backup $backup, ?string $cannotRestore = null): array
    {
        $finished = $backup->finished_at;

        return [
            'id' => $backup->id,
            'machine' => ['id' => $backup->machine_id, 'name' => $backup->machine->name],
            'service' => $backup->service,
            'serviceName' => $backup->service_name,
            'trigger' => $backup->trigger,
            'state' => $backup->state,
            'status' => self::LABELS[$backup->state] ?? ucfirst($backup->state),
            'bytes' => $backup->bytes,
            'size' => $backup->bytes !== null ? WorkspaceHealth::bytes($backup->bytes) : null,
            'destination' => $backup->destination === null ? null : ['id' => $backup->destination->id, 'name' => $backup->destination->name],
            'location' => $backup->location,
            'error' => $backup->error,
            'startedAt' => $backup->created_at?->toIso8601String(),
            'finishedAt' => $finished?->toIso8601String(),
            'seconds' => $finished !== null && $backup->created_at !== null ? (int) max(0, $backup->created_at->diffInSeconds($finished)) : null,
            'jobId' => $backup->daemon_job_id,
            'canRestore' => $cannotRestore === null && $backup->state === 'succeeded' && $backup->snapshot !== null,
            'cannotRestore' => $cannotRestore,
            'lastRestore' => $backup->restore_state === null ? null : [
                'state' => $backup->restore_state,
                'error' => $backup->restore_error,
                'at' => $backup->restored_at?->toIso8601String(),
                'jobId' => $backup->restore_job_id,
            ],
        ];
    }

    /**
     * A machine's backup settings, as a row of the Machines tab: where they
     * go, how often, when it last worked, and whether it can back up now.
     *
     * @return array<string, mixed>
     */
    public static function machine(Machine $machine, BackupRunner $runner, McpContext $context): array
    {
        $destination = $machine->backupDestination;
        $reason = $runner->unavailable($machine);

        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'status' => Present::connection($machine),
            'schedule' => $machine->backup_schedule ?? 'off',
            'destination' => $destination === null ? null : [
                'id' => $destination->id,
                'name' => $destination->name,
                'type' => $destination->type,
                'status' => $destination->status,
            ],
            'lastBackupAt' => $machine->last_backup_at?->toIso8601String(),
            'backupableServices' => count($machine->backupableServices()),
            'canBackUpNow' => $reason === null,
            'unavailable' => $reason,
            'url' => $context->url('/backups'),
        ];
    }

    /**
     * A destination as the Destinations tab lists it. Never its keys, the
     * storage endpoint or anything else that would let someone reach it.
     *
     * @param  Collection<int, Machine>  $machines  the workspace's machines
     * @return array<string, mixed>
     */
    public static function destination(BackupDestination $destination, Collection $machines, ?Backup $last = null): array
    {
        $using = $machines->where('backup_destination_id', $destination->id)->sortBy('name')->values();
        $receiver = $destination->type === 'machine' ? $machines->firstWhere('id', $destination->machine_id) : null;

        return [
            'id' => $destination->id,
            'name' => $destination->name,
            'type' => $destination->type,
            'kind' => $destination->type === 's3' ? 'S3-compatible bucket' : 'Another machine (SFTP)',
            'where' => $destination->describe(),
            'receivingMachine' => $receiver === null ? null : ['id' => $receiver->id, 'name' => $receiver->name],
            'status' => $destination->status,
            'statusMessage' => $destination->status_message,
            'machines' => $using->map(fn (Machine $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'schedule' => $m->backup_schedule ?? 'off',
            ])->all(),
            'machineCount' => $using->count(),
            'lastBackup' => $last === null ? null : [
                'id' => $last->id,
                'machine' => $last->machine->name,
                'serviceName' => $last->service_name,
                'state' => $last->state,
                'at' => ($last->finished_at ?? $last->created_at)?->toIso8601String(),
            ],
            'createdAt' => $destination->created_at?->toIso8601String(),
        ];
    }

    /**
     * One of the workspace's destinations, by id or name (any case).
     */
    public static function findDestination(McpContext $context, mixed $reference): BackupDestination
    {
        $reference = trim((string) $reference);
        $query = $context->workspace->backupDestinations();

        if (ctype_digit($reference) && ($found = (clone $query)->find((int) $reference)) !== null) {
            return $found;
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several backup destinations are called “{$reference}”: ".$matches->map(fn (BackupDestination $d) => "#{$d->id}")->implode(', ').'. Use the id.');
        }

        $names = (clone $query)->orderBy('name')->limit(10)->pluck('name');

        throw new ToolError("No backup destination “{$reference}” in {$context->workspace->name}.".($names->isNotEmpty()
            ? ' There are: '.$names->implode(', ').'.'
            : ' There are none yet: add one on the Backups page ('.$context->url('/backups').').'));
    }

    /**
     * A rule for references to machines and destinations: an id or a name.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }
}
