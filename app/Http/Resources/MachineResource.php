<?php

namespace App\Http\Resources;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\SecurityScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Machine
 */
class MachineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip' => $this->ip_address,
            'environment' => $this->environment->value,
            'provider' => $this->provider,
            // Its details: set by hand, provider and region stay.
            'providerManual' => (bool) $this->provider_manual,
            'region' => $this->region,
            'regionManual' => (bool) $this->region_manual,
            'tier' => $this->tier,
            'labels' => (object) ($this->labels ?? []),
            'services' => $this->services_count,
            'memory' => $this->memory_used_mb === null
                ? '—'
                : number_format($this->memory_used_mb / 1024, 1).' GB',
            'memoryMb' => $this->memory_used_mb,
            'cpu' => $this->cpu_percent ?? 0,
            'groupId' => $this->machine_group_id,
            'lastBackup' => $this->last_backup_at?->diffForHumans(['short' => true]) ?? __('Never'),
            'uid' => $this->uid,
            'iconUrl' => $this->iconUrl(),
            'connection' => self::connection($this->resource),
            'daemonVersion' => $this->daemon_version,
            'lastSeen' => $this->last_seen_at?->diffForHumans(['short' => true]),
            // Retired app versions left out; live apps carry their stable name.
            'inventory' => $this->inventory === null ? null : [
                ...$this->inventory,
                'services' => AppReleases::visible($this->resource),
            ],
            'facts' => $this->facts,
            // Live usage per mount, fullest first.
            'disks' => $this->disks ?? [],
            'diskPercent' => $this->disk_percent,
            'maintenance' => $this->inMaintenance() ? [
                'until' => $this->maintenance_until->toIso8601String(),
                'reason' => $this->maintenance_reason,
                'public' => $this->maintenance_public,
            ] : null,
            ...$this->setup(),
        ];
    }

    /**
     * How the machine's link is doing: demo, connecting, online, degraded
     * or offline. On its own for lists that need only this, without the
     * rest of the resource (whose setup check queries per machine).
     */
    public static function connection(Machine $machine): string
    {
        return $machine->uid === null ? 'demo' : ($machine->last_seen_at === null ? 'connecting' : ($machine->disconnected_at !== null || $machine->last_seen_at->lt(now()->subSeconds(60)) ? 'offline' : ($machine->last_seen_at->lt(now()->subSeconds(30)) ? 'degraded' : 'online')));
    }

    /**
     * Where a newly connected server is in its setup: connecting, then
     * discovering (first scan), then securing (the security check). Once
     * done, and for a while after, how many scan findings it has.
     *
     * @return array{setup: string|null, setupFindings: int|null}
     */
    private function setup(): array
    {
        if ($this->uid === null) {
            return ['setup' => null, 'setupFindings' => null];
        }
        if ($this->last_seen_at === null) {
            return ['setup' => 'connecting', 'setupFindings' => null];
        }
        if ($this->inventory === null) {
            return ['setup' => 'discovering', 'setupFindings' => null];
        }

        $scan = DaemonJob::query()
            ->where('machine_id', $this->id)
            ->where('kind', 'package_updates')
            ->oldest()
            ->first();

        if ($scan?->isActive() && ! $scan->isStale()) {
            return ['setup' => 'securing', 'setupFindings' => null];
        }

        // Shortly after setup, say how it went; later the row is just a row.
        $recent = $scan !== null && $scan->updated_at?->gt(now()->subMinutes(15));
        $findings = null;
        if ($recent) {
            $report = app(SecurityScan::class)->report($this->resource, $scan);
            $findings = count(array_filter($report['checks'], fn (array $c) => in_array($c['state'], ['warn', 'issue'], true)));
        }

        return ['setup' => null, 'setupFindings' => $findings];
    }
}
