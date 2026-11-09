<?php

namespace App\Support\Health;

use App\Enums\MachineEnvironment;
use App\Models\Backup;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\Firewall;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything in a workspace that needs someone to look at it, worst first,
 * each said plainly with what it means and where to fix it. Built for people
 * who don't know what a full disk does to Postgres as much as for those who
 * do and just want the list.
 *
 * @phpstan-type Issue array{id: string, severity: string, title: string, detail: string, href: string, action: string, since: string|null, machineId: int|null, ask: string|null}
 */
class WorkspaceHealth
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const NOTICE = 'notice';

    /** Disk fullness that warns, and that's critical. */
    public const DISK_WARN = 85;

    public const DISK_CRITICAL = 95;

    public const MEMORY_WARN = 92;

    /** How long a server can miss reports before it counts as offline. */
    public const OFFLINE_AFTER_SECONDS = 120;

    /**
     * @param  Collection<int, Machine>|null  $machines  already loaded, to save a query
     * @return list<Issue>
     */
    public function issues(Workspace $workspace, ?Collection $machines = null): array
    {
        // Marked as read, unless it has happened again since.
        $dismissed = $workspace->dismissed_issues ?? [];

        return array_values(array_filter(
            $this->all($workspace, $machines),
            fn (array $issue) => ! array_key_exists($issue['id'], $dismissed) || $dismissed[$issue['id']] !== $issue['since'],
        ));
    }

    /**
     * Mark issues as read for everyone in the workspace. Each one stays
     * hidden until it starts again (a new "since"). Ones that have cleared
     * up are forgotten.
     *
     * @param  list<string>  $ids
     * @return int how many were marked
     */
    public function dismiss(Workspace $workspace, array $ids): int
    {
        $current = collect($this->all($workspace))->keyBy('id');
        $dismissed = array_intersect_key($workspace->dismissed_issues ?? [], $current->all());

        $marked = 0;
        foreach ($ids as $id) {
            if ($current->has($id)) {
                $dismissed[$id] = $current[$id]['since'];
                $marked++;
            }
        }
        $workspace->forceFill(['dismissed_issues' => $dismissed ?: null])->save();

        return $marked;
    }

    /**
     * @param  Collection<int, Machine>|null  $machines
     * @return list<Issue>
     */
    private function all(Workspace $workspace, ?Collection $machines = null): array
    {
        $machines ??= $workspace->machines()->get();
        $issues = [];

        foreach ($machines as $machine) {
            $found = $this->machineIssues($machine);
            if ($machine->inMaintenance()) {
                // Expected while someone works on it: shown, not raised.
                $found = array_map(fn (array $issue) => [...$issue, 'severity' => self::NOTICE], $found);
                array_unshift($found, $this->issue("machine-{$machine->id}-maintenance", self::NOTICE,
                    "{$machine->name} is in maintenance until {$machine->maintenance_until->format('H:i')} UTC",
                    ($machine->maintenance_reason ? rtrim($machine->maintenance_reason, '.').'. ' : '').'Alerts and automatic workflows about it are held until then; anything still wrong afterwards is alerted.',
                    "/machines/{$machine->id}", 'Open machine', null, $machine));
            }
            array_push($issues, ...$found);
        }

        $monitors = $workspace->monitors()->with('machine')->where('paused', false)->get();
        foreach ($monitors as $monitor) {
            $found = $this->monitorIssues($monitor);
            if ($monitor->muted()) {
                $found = array_map(fn (array $issue) => [...$issue, 'severity' => self::NOTICE, 'title' => $issue['title'].' (muted)'], $found);
            }
            array_push($issues, ...$found);
        }

        array_push($issues, ...$this->backupIssues($machines));

        $rank = [self::CRITICAL => 0, self::WARNING => 1, self::NOTICE => 2];
        usort($issues, fn (array $a, array $b) => [$rank[$a['severity']], $b['since'] ?? ''] <=> [$rank[$b['severity']], $a['since'] ?? '']);

        return $issues;
    }

    /**
     * One machine's problems, for its own page: the same ones Home lists,
     * dismissed or not.
     *
     * @return list<Issue>
     */
    public function forMachine(Machine $machine): array
    {
        return $this->machineIssues($machine);
    }

    /**
     * @return list<Issue>
     */
    private function machineIssues(Machine $machine): array
    {
        if ($machine->uid === null) {
            return [];
        }

        $href = "/machines/{$machine->id}";
        $issues = [];
        $production = $machine->environment === MachineEnvironment::Production;

        if ($machine->last_seen_at === null) {
            if ($machine->created_at?->lt(now()->subMinutes(15))) {
                $issues[] = $this->issue("machine-{$machine->id}-never", self::WARNING,
                    "{$machine->name} never finished connecting",
                    'The install started but the daemon hasn’t reported in. Run the install command again on the machine, and check it can reach the internet on port 443.',
                    $href, 'Open machine', $machine->created_at, $machine);
            }

            return $issues;
        }

        $offline = $machine->disconnected_at !== null || $machine->last_seen_at->lt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
        if ($offline) {
            $since = $machine->disconnected_at ?? $machine->last_seen_at;
            $issues[] = $this->issue("machine-{$machine->id}-offline", $production ? self::CRITICAL : self::WARNING,
                "{$machine->name} stopped reporting",
                'The machine may be down or unreachable, or the ServerOS daemon stopped. Check it in your provider’s console; if it’s up, run “systemctl restart serverosd” on it. What’s below is from before it went quiet.',
                $href, 'Open machine', $since, $machine);
        }

        $disk = $machine->disks[0] ?? null;
        if ($disk !== null && $machine->disk_percent !== null && $machine->disk_percent >= self::DISK_WARN) {
            $free = self::bytes($disk['total'] - $disk['used']);
            $mount = $disk['mount'] === '/' ? 'Its main disk' : "The disk at {$disk['mount']}";
            $issues[] = $this->issue("machine-{$machine->id}-disk", $machine->disk_percent >= self::DISK_CRITICAL ? self::CRITICAL : self::WARNING,
                "{$machine->name} is {$machine->disk_percent}% full",
                "{$mount} has {$free} left. When it fills, databases stop writing and apps usually crash. “See what’s using it” on the machine’s page shows the biggest folders and what’s safe to clear".($machine->hasDocker() ? ', like Docker images nobody runs' : '').'. If it’s data you need, resize the disk with your provider.',
                $href, 'Open machine', null, $machine,
                "{$machine->name}’s disk is {$machine->disk_percent}% full. Find what’s using the space and free some safely. Ask me before deleting anything that isn’t clearly safe to remove.");
        }

        // Databases and caches the internet can reach. Named by their ports,
        // so another one opening raises it again after a dismissal.
        $exposed = Firewall::exposedDatabases($machine);
        if ($exposed !== []) {
            $list = implode(', ', array_map(fn (array $p) => "{$p['what']} ({$p['port']})", $exposed));
            $maybe = collect($exposed)->every(fn (array $p) => $p['exposure'] === 'unknown');
            $issues[] = $this->issue("machine-{$machine->id}-exposed-".implode('-', array_column($exposed, 'port')), self::WARNING,
                count($exposed) === 1
                    ? "{$exposed[0]['what']} on {$machine->name} ".($maybe ? 'may be' : 'is').' open to the internet'
                    : count($exposed)." databases on {$machine->name} ".($maybe ? 'may be' : 'are').' open to the internet',
                "{$list} ".(count($exposed) === 1 ? 'accepts' : 'accept').' connections from anywhere. Databases and caches should usually only be reachable from the machine itself or your own network: close the port from the machine’s Ports tab, or have it listen on 127.0.0.1.',
                "{$href}/ports", 'Review ports', null, $machine,
                "{$list} on {$machine->name} can be reached from the internet. Find out how each is exposed (the firewall, Docker publishing it, or what it listens on), restrict it so only the machine and what needs it can connect, then scan the machine again and check it worked. Choose the least disruptive fix, and ask me before stopping, removing or re-creating anything.");
        }

        // Services the watcher saw stop or crash-loop, while it's still so.
        $watched = $machine->alert_state['services'] ?? [];
        $known = [];
        // Versions a deploy retired aren't problems, even if stopped.
        foreach (AppReleases::visible($machine) as $service) {
            if (is_string($service['key'] ?? null) && ! $service['hidden']) {
                $name = (string) ($service['name'] ?? $service['key']);
                // The name someone gave it, or a deployed app's own name.
                $known[$service['key']] = ['name' => $name, 'label' => (string) ($service['label'] ?? $service['app'] ?? $name)];
            }
        }
        foreach (array_keys($watched['looping'] ?? []) as $key) {
            if (isset($known[$key])) {
                $issues[] = $this->issue("service-{$machine->id}-{$key}-loop", self::CRITICAL,
                    "{$known[$key]['label']} keeps restarting on {$machine->name}",
                    'It starts, crashes and is started again. The error is in its logs, usually just before each restart: a missing setting, a database it can’t reach, or running out of memory.',
                    '/logs?'.http_build_query(['machine' => $machine->id, 'service' => $known[$key]['name']]), 'See its logs', null, $machine,
                    "{$known[$key]['label']} on {$machine->name} keeps restarting. Find out why from its logs and fix it.");
            }
        }
        foreach ($watched['down'] ?? [] as $key => $since) {
            if (isset($known[$key])) {
                $issues[] = $this->issue("service-{$machine->id}-{$key}-down", $production ? self::CRITICAL : self::WARNING,
                    "{$known[$key]['label']} stopped on {$machine->name}",
                    'It was running and stopped without anyone stopping it from ServerOS. Its last log lines usually say why; start it again from its page once it’s fixed.',
                    '/logs?'.http_build_query(['machine' => $machine->id, 'service' => $known[$key]['name']]), 'See its logs', now()->setTimestamp((int) $since), $machine,
                    "{$known[$key]['label']} on {$machine->name} stopped on its own. Find out why from its logs, fix it and start it again.");
            }
        }

        // Containers Pterodactyl set up, whose Wings files are gone: Docker
        // won't start them until they're repaired.
        foreach (AppReleases::visible($machine) as $service) {
            $key = $service['key'] ?? null;
            if (is_string($key) && isset($known[$key]) && ($service['details']['needs_repair'] ?? null) === 'wings') {
                $issues[] = $this->issue("service-{$machine->id}-{$key}-repair", self::WARNING,
                    "{$known[$key]['label']} needs repairing before it can start on {$machine->name}",
                    'Pterodactyl set it up with files Wings creates each time it starts a server. Wings isn’t running any more, so Docker won’t start it. ServerOS can recreate it without those files and keep its data.',
                    '/machines/'.$machine->id.'/services/'.rawurlencode($key), 'Repair it', null, $machine);
            }
        }

        $memory = (int) ($machine->facts['memory_bytes'] ?? 0);
        if (! $offline && $memory > 0 && $machine->memory_used_mb !== null) {
            $percent = (int) floor($machine->memory_used_mb * 1048576 / $memory * 100);
            if ($percent >= self::MEMORY_WARN) {
                $issues[] = $this->issue("machine-{$machine->id}-memory", self::WARNING,
                    "{$machine->name} is using {$percent}% of its memory",
                    'When memory runs out, Linux stops the biggest process, often your database. See which service uses most on the machine page, then give it a limit or move to a bigger server.',
                    $href, 'See services', null, $machine);
            }
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function monitorIssues(Monitor $monitor): array
    {
        $href = "/monitors/{$monitor->id}";
        $issues = [];

        if ($monitor->status === 'down') {
            $issues[] = $this->issue("monitor-{$monitor->id}-down", self::CRITICAL,
                "{$monitor->name} is down",
                ($monitor->last_error ?? 'Checks are failing.').' Visitors to '.$monitor->target().' are likely seeing an error.',
                $href, 'Open monitor', $monitor->status_changed_at, null);
        }

        if ($monitor->ssl_expires_at !== null && $monitor->ssl_expires_at->lt(now()->addDays(Monitor::SSL_WARN_DAYS))) {
            $days = (int) floor(now()->diffInDays($monitor->ssl_expires_at, false));
            $issues[] = $this->issue("monitor-{$monitor->id}-certificate", $days <= 3 ? self::CRITICAL : self::WARNING,
                $days < 0 ? "{$monitor->name}’s certificate has expired" : "{$monitor->name}’s certificate expires in {$days} ".($days === 1 ? 'day' : 'days'),
                'Browsers block sites with an expired certificate. If it renews automatically (Let’s Encrypt, Caddy), check that renewal is working; otherwise renew it with whoever issued it.',
                $href, 'Open monitor', null, null);
        }

        return $issues;
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return list<Issue>
     */
    private function backupIssues(Collection $machines): array
    {
        $issues = [];
        $latest = Backup::query()
            ->whereIn('machine_id', $machines->modelKeys())
            ->whereIn('state', ['succeeded', 'failed'])
            ->where('created_at', '>=', now()->subDays(14))
            ->orderByDesc('id')
            ->get()
            ->unique('machine_id')
            ->keyBy('machine_id');

        foreach ($machines as $machine) {
            if ($machine->uid === null) {
                continue;
            }

            $last = $latest[$machine->id] ?? null;
            if ($last?->state === 'failed') {
                $issues[] = $this->issue("backup-{$machine->id}-failed", self::WARNING,
                    "The last backup of {$machine->name} failed",
                    ($last->error ? rtrim($last->error, '.').'. ' : '').'Until one succeeds, a mistake or a lost machine can’t be undone.',
                    '/backups', 'Open backups', $last->finished_at ?? $last->created_at, $machine);

                continue;
            }

            if ($machine->backup_schedule !== 'off') {
                $hours = $machine->backup_schedule === 'weekly' ? 24 * 8 : 36;
                $reference = $machine->last_backup_at ?? $machine->created_at;
                if ($reference !== null && $reference->lt(now()->subHours($hours))) {
                    $issues[] = $this->issue("backup-{$machine->id}-overdue", self::WARNING,
                        "{$machine->name} hasn’t been backed up ".($machine->last_backup_at ? 'since '.$machine->last_backup_at->diffForHumans() : 'yet'),
                        "It’s set to back up {$machine->backup_schedule}, but no backup has finished. The machine may be offline, or the destination unreachable.",
                        '/backups', 'Open backups', null, $machine);
                }

                continue;
            }

            if ($machine->environment === MachineEnvironment::Production && $machine->backupableServices() !== []) {
                $issues[] = $this->issue("backup-{$machine->id}-off", self::NOTICE,
                    "{$machine->name} isn’t backed up",
                    'It runs production services with nothing to restore from if the disk fails or someone deletes the wrong thing. Choose a destination and a schedule; it takes a minute.',
                    '/backups', 'Set up backups', null, $machine);
            }
        }

        return $issues;
    }

    /**
     * @return Issue
     */
    private function issue(string $id, string $severity, string $title, string $detail, string $href, string $action, ?CarbonInterface $since, ?Machine $machine, ?string $ask = null): array
    {
        return [
            'id' => $id,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'href' => $href,
            'action' => $action,
            'since' => $since?->toIso8601String(),
            'machineId' => $machine?->id,
            // What to ask Ask AI, for problems it can look into and fix with the person.
            'ask' => $ask,
        ];
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) max(0, $bytes);
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($value >= 10 || $i === 0 ? round($value) : round($value, 1)).' '.$units[$i];
    }
}
