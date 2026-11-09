<?php

namespace App\Mcp\Tools\Backup;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Backup;
use App\Models\Machine;
use App\Support\Backups\BackupRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The Backups page for an agent: each machine's backup settings and the
 * history of runs, newest first, filtered further than the page can.
 */
final class ListBackups extends Tool
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public function __construct(private readonly BackupRunner $runner) {}

    public function name(): string
    {
        return 'list_backups';
    }

    public function title(): string
    {
        return 'List backups';
    }

    public function description(): string
    {
        return 'Backups as the dashboard’s Backups page shows them. `backups` is the history, newest first: one entry per service per run, with its id, machine, service (key) and serviceName, trigger (manual, scheduled, workflow, pre-restore: the copy taken just before a restore, which restoring undoes it, or pre-deploy: the app’s data just before a deploy replaced it), state (queued, running, succeeded, failed, or skipped: the service keeps nothing outside its container), size, the destination’s name, where it was stored (location), the error if it failed, startedAt and finishedAt (ISO 8601), seconds taken and the job’s id (get_job follows it), canRestore (cannotRestore says why not: only the newest few per service stay on the machine to restore from) and lastRestore. restore_backup restores one. '
            .'`machines` (on the first page only) is each machine’s settings: schedule (off, daily or weekly), destination, lastBackupAt, how many services ServerOS can back up, and canBackUpNow, with `unavailable` saying why not (no destination, destination not ready, nothing managed, offline). Only services ServerOS manages (deployed or adopted) are backed up. '
            .'Filters: machine, service (its key or name), state, trigger, destination (id or name), since and until (ISO 8601). Up to limit entries (default '.self::DEFAULT_LIMIT.', at most '.self::MAX_LIMIT.'); for older ones pass before = nextCursor. run_backup starts one; update_backup_settings changes the schedule or destination.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('Only this machine’s backups: its id or name.'),
            'service' => Schema::string('Only backups of this service: its key (like docker:4f2a…) or its name.', maxLength: 300),
            'state' => Schema::string('Only backups in this state.', BackupView::STATES),
            'trigger' => Schema::string('Only backups started this way: manual (Back up now or run_backup), scheduled, or by a workflow.', BackupView::TRIGGERS),
            'destination' => Schema::string('Only backups sent to this destination: its id or name (list_backup_destinations shows them).', maxLength: 120),
            'since' => Schema::string('Only backups started at or after this time (ISO 8601, like 2026-05-01 or 2026-05-01T09:00:00Z).', maxLength: 40),
            'until' => Schema::string('Only backups started at or before this time (ISO 8601). A date alone means the end of that day.', maxLength: 40),
            'limit' => Schema::integer('How many backups to return (1–'.self::MAX_LIMIT.', default '.self::DEFAULT_LIMIT.').', 1, self::MAX_LIMIT),
            'before' => Schema::integer('For older backups: the nextCursor from the previous answer.', 1),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['nullable', 'max:120', BackupView::reference()],
            'service' => ['nullable', 'string', 'max:300'],
            'state' => ['nullable', 'string', Rule::in(BackupView::STATES)],
            'trigger' => ['nullable', 'string', Rule::in(BackupView::TRIGGERS)],
            'destination' => ['nullable', 'max:120', BackupView::reference()],
            'since' => ['nullable', 'string', 'max:40'],
            'until' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $machine = isset($data['machine']) ? $context->machine($data['machine']) : null;
        $destination = isset($data['destination']) ? BackupView::findDestination($context, $data['destination']) : null;
        $since = isset($data['since']) ? $this->time('since', (string) $data['since'], false) : null;
        $until = isset($data['until']) ? $this->time('until', (string) $data['until'], true) : null;
        if ($since !== null && $until !== null && $since->gt($until)) {
            throw new ToolError('since is after until: nothing can match. Swap them?');
        }
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        $machines = $context->workspace->machines()->with('backupDestination')->orderBy('name')->get();
        $services = $this->services($context, $machine, isset($data['service']) ? trim((string) $data['service']) : null);

        $rows = Backup::query()
            ->whereIn('machine_id', $machine !== null ? [$machine->id] : $machines->modelKeys())
            ->with(['machine', 'destination:id,name'])
            ->when($services, fn (Builder $q, array $s) => $q->where(fn (Builder $w) => $w
                ->whereIn('service', $s['keys'])
                ->orWhereIn('service_name', $s['names'])))
            ->when($data['state'] ?? null, fn (Builder $q, string $state) => $q->where('state', $state))
            ->when($data['trigger'] ?? null, fn (Builder $q, string $trigger) => $q->where('trigger', $trigger))
            ->when($destination, fn (Builder $q) => $q->where('backup_destination_id', $destination->id))
            ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->when($until, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->when($data['before'] ?? null, fn (Builder $q, int $id) => $q->where('id', '<', $id))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $page = $rows->take($limit);
        $more = $rows->count() > $limit;
        $cannot = $this->runner->cannotRestoreAll($page);

        $answer = [];
        if (! isset($data['before'])) {
            $answer['machines'] = $machines
                ->when($machine !== null, fn ($all) => $all->where('id', $machine->id))
                ->map(fn (Machine $m) => BackupView::machine($m, $this->runner, $context))
                ->values()
                ->all();
        }

        return [
            ...$answer,
            'backups' => $page->map(fn (Backup $b) => BackupView::backup($b, $cannot[$b->id] ?? null))->values()->all(),
            'count' => $page->count(),
            'hasMore' => $more,
            'nextCursor' => $more ? $page->last()->id : null,
            'url' => $context->url('/backups'),
        ];
    }

    /**
     * What a service filter matches: its key or the name it was backed up
     * under, and, on a machine, whatever the service's current key is.
     *
     * @return array{keys: list<string>, names: list<string>}|null
     */
    private function services(McpContext $context, ?Machine $machine, ?string $reference): ?array
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $keys = [$reference];
        $names = [$reference, ltrim($reference, '/')];
        if ($machine !== null) {
            try {
                $service = $context->service($machine, $reference);
                $keys[] = (string) $service['key'];
                if (is_string($service['name'] ?? null)) {
                    $names[] = $service['name'];
                }
            } catch (ToolError) {
                // Not on the machine now; its old backups still match by name.
            }
        }

        return ['keys' => array_values(array_unique($keys)), 'names' => array_values(array_unique($names))];
    }

    /** A time the agent gave; a date alone as `until` means the end of that day. */
    private function time(string $name, string $value, bool $endOfDay): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            throw new ToolError("{$name} isn’t a time: use ISO 8601, like 2026-05-01 or 2026-05-01T09:00:00Z.");
        }

        return $endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? $at->endOfDay() : $at;
    }
}
