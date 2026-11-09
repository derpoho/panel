<?php

namespace App\Mcp\Tools\Backup;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Support\Backups\BackupRunner;
use App\Support\DaemonJobQueue;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * "Back up now": every service ServerOS manages on a machine, to its
 * destination, one server job each. Or every machine that can be, the
 * way a workflow's "Back up every server" step does.
 */
final class RunBackup extends Tool
{
    public function __construct(
        private readonly BackupRunner $runner,
        private readonly DaemonJobQueue $queue,
    ) {}

    public function name(): string
    {
        return 'run_backup';
    }

    public function title(): string
    {
        return 'Back up now';
    }

    public function description(): string
    {
        return 'Backs up a machine now, as the Backups page’s “Back up now” does: every service ServerOS manages on it (deployed or adopted; others aren’t backed up) is copied to the machine’s backup destination, one job per service. Needs a destination that is ready (update_backup_settings sets one) and the machine online; otherwise it says why and starts nothing. '
            .'Pass machine "'.WorkflowSteps::ALL_MACHINES.'" to back up every machine in the workspace, as a workflow’s “Back up every machine” step does: machines that are offline or not set up are skipped and listed with the reason. That starts work on every machine at once, so tell the person first. '
            .'Returns the backups started (ids, services, states) and their jobs. Backups can take minutes to hours: pass wait (up to 25 seconds) for small ones, then follow with list_backups (state, size, error) or get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('The machine to back up: its id or name, or "'.WorkflowSteps::ALL_MACHINES.'" for every machine that can be.'),
            'wait' => Schema::integer('Seconds to wait for the backups to finish before answering (0–25, default 0). They keep running either way.', 0, McpContext::MAX_WAIT_SECONDS),
        ], ['machine']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', 'max:120', BackupView::reference()],
            'wait' => ['sometimes', 'integer', 'min:0', 'max:'.McpContext::MAX_WAIT_SECONDS],
        ]);
        $wait = (int) ($data['wait'] ?? 0);

        if (trim((string) $data['machine']) === WorkflowSteps::ALL_MACHINES) {
            return $this->everything($context, $wait);
        }

        $machine = $context->machine($data['machine']);
        $from = (int) Backup::query()->where('machine_id', $machine->id)->max('id');

        try {
            $started = $this->runner->run($machine, $context->user, 'manual');
        } catch (RuntimeException $e) {
            throw new ToolError("Couldn’t back up {$machine->name}: {$e->getMessage()}");
        }
        $context->record('backup.run', $machine->name, $machine, ['services' => $started]);

        $backups = $this->settle(Backup::query()->where('machine_id', $machine->id)->where('id', '>', $from)->get(), $wait);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'started' => $started,
            ...$backups,
            'note' => $started === 0 ? 'No backup could be sent to the machine; see each one’s error.' : null,
            'url' => $context->url('/backups'),
        ];
    }

    /**
     * Every machine that can be backed up; the rest are skipped with the
     * reason, as the workflow step notes them.
     *
     * @return array<string, mixed>
     */
    private function everything(McpContext $context, int $wait): array
    {
        $machines = $context->workspace->machines()->with('backupDestination')->orderBy('name')->get();
        if ($machines->isEmpty()) {
            throw new ToolError('Connect a machine first.');
        }

        $from = (int) Backup::query()->whereIn('machine_id', $machines->modelKeys())->max('id');
        [$done, $skipped, $services] = [[], [], 0];

        foreach ($machines as $machine) {
            if (($reason = $this->queue->unavailable($machine)) !== null) {
                $skipped[] = ['id' => $machine->id, 'name' => $machine->name, 'reason' => $reason];

                continue;
            }
            try {
                $started = $this->runner->run($machine, $context->user, 'manual');
            } catch (RuntimeException $e) {
                $skipped[] = ['id' => $machine->id, 'name' => $machine->name, 'reason' => $e->getMessage()];

                continue;
            }
            $context->record('backup.run', $machine->name, $machine, ['services' => $started]);
            $done[] = ['id' => $machine->id, 'name' => $machine->name, 'services' => $started];
            $services += $started;
        }

        if ($done === []) {
            throw new ToolError('No machine could be backed up. '.collect($skipped)->map(fn (array $s) => "{$s['name']}: {$s['reason']}")->implode(' '));
        }

        $backups = $this->settle(
            Backup::query()->whereIn('machine_id', array_column($done, 'id'))->where('id', '>', $from)->get(),
            $wait,
        );

        return [
            'machines' => $done,
            'skipped' => $skipped,
            'started' => $services,
            ...$backups,
            'url' => $context->url('/backups'),
        ];
    }

    /**
     * The backups just started, after waiting for them as long as asked,
     * with their jobs.
     *
     * @param  Collection<int, Backup>  $backups
     * @return array{backups: list<array<string, mixed>>, jobs: list<array<string, mixed>>, finished: bool}
     */
    private function settle(Collection $backups, int $wait): array
    {
        $ids = $backups->pluck('daemon_job_id')->filter()->values()->all();
        $jobs = DaemonJob::query()->whereIn('id', $ids)->orderBy('created_at')->get();

        // One wait for them all, polled as McpContext::waitForJob polls one.
        $deadline = microtime(true) + max(0, min(McpContext::MAX_WAIT_SECONDS, $wait));
        while ($jobs->contains(fn (DaemonJob $job) => ! in_array($job->state, McpContext::FINISHED, true)) && microtime(true) < $deadline) {
            usleep(500_000);
            $jobs = DaemonJob::query()->whereIn('id', $ids)->orderBy('created_at')->get();
        }

        $backups = Backup::query()->whereKey($backups->modelKeys())->with(['machine:id,name', 'destination:id,name'])->orderBy('id')->get();

        return [
            'backups' => array_values($backups->map(fn (Backup $b) => BackupView::backup($b))->all()),
            'jobs' => array_values($jobs->map(fn (DaemonJob $job) => Present::job($job, 10))->all()),
            'finished' => $backups->every(fn (Backup $b) => ! in_array($b->state, ['queued', 'running'], true)),
        ];
    }
}
