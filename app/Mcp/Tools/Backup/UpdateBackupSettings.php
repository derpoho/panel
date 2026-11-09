<?php

namespace App\Mcp\Tools\Backup;

use App\Http\Controllers\BackupController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\BackupDestination;
use App\Support\Audit\AuditContext;
use App\Support\Backups\BackupRunner;
use Illuminate\Validation\Rule;

/**
 * Where a machine's backups go and how often they run: the destination
 * and schedule pickers on the Backups page's Machines tab.
 */
final class UpdateBackupSettings extends Tool
{
    /** What clears the destination. */
    public const NONE = 'none';

    public function __construct(private readonly BackupRunner $runner) {}

    public function name(): string
    {
        return 'update_backup_settings';
    }

    public function title(): string
    {
        return 'Change a machine’s backup schedule or destination';
    }

    public function description(): string
    {
        return 'Sets where a machine’s backups go and how often they run, as the Backups page’s Machines tab does. '
            .'destination: a backup destination’s id or name (list_backup_destinations shows them), or "'.self::NONE.'" to stop sending backups anywhere (which also turns the schedule off). A machine can’t keep its own backups. '
            .'schedule: off, daily or weekly; scheduled backups run once a day or week, at the first hourly check after they’re due. A schedule other than off needs a destination. '
            .'Give either or both; what you leave out stays as it is, except that picking a destination for a machine that had none turns on daily backups unless you pass a schedule, as the page does. '
            .'Returns the machine’s backup settings afterwards, whether anything changed, and whether it can back up now (a destination still being set up can’t take backups yet). run_backup backs it up straight away.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('The machine whose backups to set: its id or name.'),
            'destination' => Schema::string('The backup destination: its id or name, or "'.self::NONE.'" for none.', maxLength: 120),
            'schedule' => Schema::string('How often to back up on their own: off, daily or weekly.', BackupController::SCHEDULES),
        ], ['machine']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', 'max:120', BackupView::reference()],
            'destination' => ['nullable', 'max:120', BackupView::reference()],
            'schedule' => ['nullable', 'string', Rule::in(BackupController::SCHEDULES)],
        ]);
        if (! isset($data['destination']) && ! isset($data['schedule'])) {
            throw new ToolError('Say what to change: a destination, a schedule, or both.');
        }

        $machine = $context->machine($data['machine']);
        $hadDestination = $machine->backup_destination_id !== null;
        $current = $machine->backup_schedule ?? 'off';

        $destination = match (true) {
            ! isset($data['destination']) => $machine->backupDestination,
            mb_strtolower(trim((string) $data['destination'])) === self::NONE => null,
            default => BackupView::findDestination($context, $data['destination']),
        };

        // As the page fills in what wasn't changed: a first destination
        // turns on daily backups, and no destination means none run.
        $schedule = $data['schedule'] ?? match (true) {
            $destination === null => 'off',
            ! $hadDestination && $current === 'off' => 'daily',
            default => $current,
        };

        if ($destination?->machine_id === $machine->id) {
            throw new ToolError('A machine can\'t keep its own backups; pick somewhere else.');
        }
        if ($destination === null && $schedule !== 'off') {
            throw new ToolError('Choose where its backups go first.');
        }

        $machine->forceFill([
            'backup_destination_id' => $destination?->id,
            'backup_schedule' => $schedule,
        ]);
        $changed = $machine->isDirty(['backup_destination_id', 'backup_schedule']);
        if ($changed) {
            $context->record('backups.schedule', $machine->name, $machine, ['schedule' => $schedule, 'destination' => $destination?->name]);
        } else {
            AuditContext::nothingChanged();
        }
        $machine->save();
        $machine->setRelation('backupDestination', $destination);

        return [
            'machine' => BackupView::machine($machine, $this->runner, $context),
            'changed' => $changed,
            'note' => $this->note($destination, $schedule),
        ];
    }

    private function note(?BackupDestination $destination, string $schedule): ?string
    {
        return match (true) {
            $destination !== null && $destination->status !== 'ready' => "{$destination->name} isn't ready yet ({$destination->status}), so backups can’t go there until it is. list_backup_destinations shows its status.",
            $destination !== null && $schedule === 'off' => "Backups go to {$destination->name} when you run one (run_backup); none run on a schedule.",
            default => null,
        };
    }
}
