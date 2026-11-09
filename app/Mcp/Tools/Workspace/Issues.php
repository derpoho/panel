<?php

namespace App\Mcp\Tools\Workspace;

use App\Mcp\McpContext;
use App\Models\Machine;
use App\Models\Monitor;
use App\Support\Health\WorkspaceHealth;
use Illuminate\Support\Collection;

/**
 * The workspace Home's "Needs attention" list as an agent reads it: each
 * item as WorkspaceHealth says it, plus what kind of problem it is, the
 * machine, service and monitor it's about, and a short next step.
 */
final class Issues
{
    public const SEVERITIES = [WorkspaceHealth::CRITICAL, WorkspaceHealth::WARNING, WorkspaceHealth::NOTICE];

    public function __construct(private readonly WorkspaceHealth $health) {}

    /**
     * What the Home shows now, worst first, without the ones marked as read.
     *
     * @return list<array<string, mixed>>
     */
    public function current(McpContext $context): array
    {
        // In the Home's order: machines by name.
        $machines = $context->workspace->machines()->orderBy('name')->get();

        return $this->health->issues($context->workspace, $machines);
    }

    /**
     * @param  list<array<string, mixed>>  $issues  from current()
     * @return list<array<string, mixed>>
     */
    public function present(McpContext $context, array $issues): array
    {
        $machines = $context->workspace->machines()->get()->keyBy('id');
        $monitorIds = collect($issues)->map(fn (array $i) => self::parse($i['id'])['monitorId'])->filter()->unique()->values();
        $monitors = $monitorIds->isEmpty()
            ? collect()
            : $context->workspace->monitors()->with('machine')->whereIn('id', $monitorIds)->get()->keyBy('id');

        return array_map(fn (array $issue) => $this->one($context, $issue, $machines, $monitors), $issues);
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, Monitor>  $monitors
     * @return array<string, mixed>
     */
    private function one(McpContext $context, array $issue, Collection $machines, Collection $monitors): array
    {
        $parsed = self::parse($issue['id']);
        $machineId = $issue['machineId'] ?? $parsed['machineId'];
        $machine = $machineId !== null ? ($machines[$machineId] ?? null) : null;
        $monitor = $parsed['monitorId'] !== null ? ($monitors[$parsed['monitorId']] ?? null) : null;
        $machine ??= $monitor?->machine;

        $service = null;
        if ($machine !== null && $parsed['serviceKey'] !== null) {
            $entry = $context->services($machine)->first(fn (array $s) => $s['key'] === $parsed['serviceKey']);
            $described = $entry !== null ? $context->describeService($machine, $entry) : null;
            $service = [
                'key' => $parsed['serviceKey'],
                'name' => $described['display'] ?? $parsed['serviceKey'],
                'url' => $context->url('/machines/'.$machine->id.'/services/'.rawurlencode(($described['app'] ?? null) ? 'app:'.$described['app'] : $parsed['serviceKey'])),
            ];
        }

        return [
            'id' => $issue['id'],
            'severity' => $issue['severity'],
            'kind' => $parsed['kind'],
            'title' => $issue['title'],
            'detail' => $issue['detail'],
            'fix' => $this->fix($parsed['kind'], $machine, $monitor),
            'since' => $issue['since'],
            'machine' => $machine ? ['id' => $machine->id, 'name' => $machine->name] : null,
            'service' => $service,
            'monitor' => $monitor ? ['id' => $monitor->id, 'name' => $monitor->name, 'target' => $monitor->target()] : null,
            'action' => $issue['action'],
            'url' => $context->url($issue['href']),
        ];
    }

    /**
     * What an issue id says: the kind of problem and what it's about. Ids
     * look like machine-12-disk, service-12-docker:4f2a…-down, monitor-3-down
     * or backup-12-failed.
     *
     * @return array{kind: string, machineId: int|null, monitorId: int|null, serviceKey: string|null}
     */
    public static function parse(string $id): array
    {
        $out = ['kind' => 'other', 'machineId' => null, 'monitorId' => null, 'serviceKey' => null];

        if (preg_match('/^machine-(\d+)-(maintenance|never|offline|disk|memory)$/', $id, $m)) {
            return [...$out, 'kind' => 'machine.'.($m[2] === 'never' ? 'never_connected' : $m[2]), 'machineId' => (int) $m[1]];
        }
        if (preg_match('/^service-(\d+)-(.+)-(loop|down|repair)$/', $id, $m)) {
            $kind = ['loop' => 'service.crash_loop', 'down' => 'service.down', 'repair' => 'service.needs_repair'][$m[3]];

            return [...$out, 'kind' => $kind, 'machineId' => (int) $m[1], 'serviceKey' => $m[2]];
        }
        if (preg_match('/^monitor-(\d+)-(down|certificate)$/', $id, $m)) {
            return [...$out, 'kind' => 'monitor.'.$m[2], 'monitorId' => (int) $m[1]];
        }
        if (preg_match('/^backup-(\d+)-(failed|overdue|off)$/', $id, $m)) {
            return [...$out, 'kind' => 'backup.'.$m[2], 'machineId' => (int) $m[1]];
        }

        return $out;
    }

    /**
     * The next step, in a line, where there's something specific to say.
     */
    private function fix(string $kind, ?Machine $machine, ?Monitor $monitor): ?string
    {
        $disk = $machine?->disks[0] ?? null;

        return match ($kind) {
            'machine.maintenance' => 'Nothing to fix: alerts about it are held while the work goes on. End the maintenance early once it’s done.',
            'machine.never_connected' => 'Run the install command again on the machine, and check it can reach the internet on port 443.',
            'machine.offline' => 'Check the machine in '.($machine?->provider ? "{$machine->provider}’s" : 'the provider’s').' console; if it’s up, run “systemctl restart serverosd” on it.',
            'machine.disk' => 'Free space'.($disk !== null ? " on {$disk['mount']} (".WorkspaceHealth::bytes((int) $disk['total'] - (int) $disk['used']).' left)' : '').': call get_disk_usage to see the biggest folders and what’s safe to clear, show the person, and let them choose; or resize the disk with the provider.',
            'machine.memory' => 'See which service uses the most memory, then give it a limit or move to a bigger machine.',
            'service.crash_loop' => 'Read its logs just before a restart for the error: often a missing setting, a database it can’t reach, or running out of memory. Fix that rather than restarting it again.',
            'service.down' => 'Read its last log lines for why it stopped; once that’s fixed, start it again.',
            'service.needs_repair' => 'Ask the person, then repair_service: it recreates the container without the Wings files, with the same image, settings and data, and restart: unless-stopped.',
            'monitor.down' => 'Check that what serves '.($monitor?->target() ?? 'it').' is running'.($monitor?->machine ? " on {$monitor->machine->name}" : '').', and its logs.'.($monitor?->last_error ? " Last error: {$monitor->last_error}" : ''),
            'monitor.certificate' => 'Renew the certificate'.($monitor?->target() ? ' for '.$monitor->target() : '').'; if it renews on its own (Let’s Encrypt, Caddy), find out why renewal stopped.',
            'backup.failed' => 'Fix what made it fail (often the destination’s credentials, space or reachability), then run a backup again.',
            'backup.overdue' => 'Check '.($machine ? $machine->name : 'the machine').' is online and its backup destination is reachable, then run a backup.',
            'backup.off' => 'Choose a backup destination and a schedule for '.($machine ? $machine->name : 'it').' on the Backups page.',
            default => null,
        };
    }
}
