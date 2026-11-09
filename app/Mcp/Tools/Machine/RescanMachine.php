<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\DaemonJob;
use App\Models\Machine;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Look at what's running on a machine now rather than waiting for its
 * hourly scan: the Refresh button. It only reads, so anyone in the
 * workspace can, and a scan already under way is reused.
 */
final class RescanMachine extends Tool
{
    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'rescan_machine';
    }

    public function title(): string
    {
        return 'Rescan a machine’s services';
    }

    public function description(): string
    {
        return 'Asks a machine to scan its services and ports now (machines rescan on their own every hour and after deploys), so list_services and get_machine show what is running right now. Only reads the machine. A scan already under way is reused rather than started again. Takes a few seconds: pass wait (up to 25 seconds) to answer once it has finished, with the new service counts. If the machine is offline, says so and gives the time of the last scan.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'wait' => ServerWork::waitSchema(),
        ], ['machine']);
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', ServerWork::reference()],
            'wait' => ServerWork::waitRules(),
        ]);
        $machine = $context->machine($data['machine']);
        ServerWork::throttle($context, 'rescan', 20);
        $before = $machine->inventory['scanned_at'] ?? null;

        if (($reason = $this->work->unavailable($machine)) !== null) {
            return $this->answer($context, $machine, null, $reason);
        }

        $running = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'discover')
            ->whereIn('state', ['queued', 'running'])
            ->where('created_at', '>', now()->subMinutes(2))
            ->latest()
            ->first();

        try {
            $job = $running ?? $this->work->queue($context, $machine, 'discover', ['type' => 'discover'], 120);
        } catch (RuntimeException) {
            return $this->answer($context, $machine, null, 'Couldn’t reach the machine. Showing the last scan.');
        }

        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));
        $machine->refresh();

        return $this->answer($context, $machine, $job, null, $running !== null, ($machine->inventory['scanned_at'] ?? null) !== $before);
    }

    /** @return array<string, mixed> */
    private function answer(McpContext $context, Machine $machine, ?DaemonJob $job, ?string $note, bool $reused = false, bool $updated = false): array
    {
        $scanned = $machine->inventory['scanned_at'] ?? null;
        $services = $context->services($machine);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'started' => $job !== null && ! $reused,
            'reusedRunningScan' => $reused,
            // A scan's own result is the inventory: the counts below say it.
            'job' => $job === null ? null : array_diff_key(Present::job($job, 10), ['result' => true]),
            'scanUpdated' => $updated,
            'lastScannedAt' => is_int($scanned) ? Carbon::createFromTimestamp($scanned)->toIso8601String() : null,
            'services' => [
                'total' => $services->count(),
                'byStatus' => $services->countBy(fn (array $s) => is_string($s['status'] ?? null) ? $s['status'] : 'unknown')->sortDesc()->all(),
            ],
            'note' => $note,
        ];
    }
}
