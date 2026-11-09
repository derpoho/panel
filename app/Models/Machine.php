<?php

namespace App\Models;

use App\Enums\MachineEnvironment;
use App\Exceptions\MachineLimitReached;
use Carbon\CarbonImmutable;
use Database\Factories\MachineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * A server connected to a workspace. Metrics hold the latest reported values.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $ip_address
 * @property string $provider
 * @property bool $provider_manual set by hand: detection leaves it alone
 * @property string|null $region
 * @property bool $region_manual set by hand: detection leaves it alone
 * @property string|null $tier
 * @property array<string, string>|null $labels the workspace's own key/value labels
 * @property MachineEnvironment $environment
 * @property int $services_count
 * @property int|null $memory_used_mb
 * @property int|null $cpu_percent
 * @property CarbonImmutable|null $last_backup_at
 * @property string|null $uid
 * @property string|null $certificate_serial
 * @property string|null $daemon_version
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $connected_at when the daemon last said hello (connected)
 * @property CarbonImmutable|null $disconnected_at
 * @property CarbonImmutable|null $inventory_scanned_at
 * @property int|null $machine_group_id
 * @property int|null $machine_policy_id the policy it picked itself; null follows its group's, else the workspace's default
 * @property bool $policy_pending its policy couldn't reach the daemon yet: sent when it next says hello
 * @property int|null $backup_destination_id
 * @property string $backup_schedule off, daily or weekly
 * @property array<string, mixed>|null $facts
 * @property array<string, mixed>|null $inventory
 * @property array<string, string>|null $service_labels names given to services in the panel
 * @property array<string, array<string, mixed>>|null $app_settings per deployed app, by its name
 * @property CarbonImmutable|null $rescan_wanted_at a rescan to send when the machine next reports in
 * @property string|null $icon_path an uploaded icon, on the public disk
 * @property array<string, string>|null $service_icons uploaded service icons, by ServiceLabels::identity()
 * @property list<string>|null $hidden_services services hidden from the panel's lists, by ServiceLabels::identity()
 * @property string|null $log_ip_masking off, partial or hash: IPs masked in log lines on the machine
 * @property array{services: list<string>, patterns: list<array{service: string|null, pattern: string}>}|null $log_skip services and line patterns whose logs never leave the machine
 * @property list<string>|null $log_muted_services services whose log lines aren't kept, by ServiceLabels::identity()
 * @property array<string, array<string, int|float|null>>|null $service_stats
 * @property list<array{mount: string, used: int, total: int}>|null $disks
 * @property int|null $disk_percent
 * @property array<string, int>|null $alert_state
 * @property CarbonImmutable|null $maintenance_until
 * @property string|null $maintenance_reason
 * @property bool $maintenance_public
 * @property CarbonImmutable|null $updates_checked_at when its OS updates were last checked (PackageUpdates)
 * @property int $updates_count OS updates waiting at that check
 * @property int $security_updates_count of those, security updates
 * @property bool $reboot_required an installed update waits for a restart
 * @property list<string>|null $held_packages packages held at their version
 * @property array{id: string|null, version_id: string|null, pretty_name: string|null}|null $os_release
 * @property bool $updates_complete false when the list came from an older daemon's capped lines
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'ip_address', 'provider', 'environment', 'services_count', 'memory_used_mb', 'cpu_percent', 'last_backup_at'])]
class Machine extends Model
{
    /** @use HasFactory<MachineFactory> */
    use HasFactory;

    /**
     * Refuse to connect machines beyond the workspace's plan.
     */
    protected static function booted(): void
    {
        static::creating(function (Machine $machine) {
            if (! $machine->workspace->canAddMachine()) {
                throw MachineLimitReached::for($machine->workspace);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => MachineEnvironment::class,
            'last_backup_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'inventory_scanned_at' => 'datetime',
            'facts' => 'array',
            'inventory' => 'array',
            'service_labels' => 'array',
            'app_settings' => 'array',
            'rescan_wanted_at' => 'datetime',
            'service_icons' => 'array',
            'hidden_services' => 'array',
            'log_muted_services' => 'array',
            'log_skip' => 'array',
            'service_stats' => 'array',
            'disks' => 'array',
            'alert_state' => 'array',
            'maintenance_until' => 'datetime',
            'maintenance_public' => 'boolean',
            'policy_pending' => 'boolean',
            'provider_manual' => 'boolean',
            'region_manual' => 'boolean',
            'labels' => 'array',
            'updates_checked_at' => 'datetime',
            'reboot_required' => 'boolean',
            'held_packages' => 'array',
            'os_release' => 'array',
            'updates_complete' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The policy it picked itself, if any.
     *
     * @return BelongsTo<MachinePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(MachinePolicy::class, 'machine_policy_id');
    }

    /**
     * The OS updates its last check found.
     *
     * @return HasMany<MachinePackageUpdate, $this>
     */
    public function packageUpdates(): HasMany
    {
        return $this->hasMany(MachinePackageUpdate::class);
    }

    /** @return BelongsTo<MachineGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MachineGroup::class, 'machine_group_id');
    }

    /**
     * @return HasMany<ServiceGroup, $this>
     */
    public function serviceGroups(): HasMany
    {
        return $this->hasMany(ServiceGroup::class);
    }

    /**
     * @return HasOne<StatusPage, $this>
     */
    public function statusPage(): HasOne
    {
        return $this->hasOne(StatusPage::class);
    }

    /**
     * Whether someone has said it's under planned work right now: alerts
     * and event workflows about it hold off until the window ends.
     */
    public function inMaintenance(): bool
    {
        return $this->maintenance_until !== null && $this->maintenance_until->isFuture();
    }

    /**
     * Whether the daemon has reported in the last minute.
     */
    public function isOnline(): bool
    {
        return $this->uid !== null
            && $this->disconnected_at === null
            && $this->last_seen_at !== null
            && $this->last_seen_at->gte(now()->subSeconds(60));
    }

    /**
     * @return BelongsTo<BackupDestination, $this>
     */
    public function backupDestination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class);
    }

    /**
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function iconUrl(): ?string
    {
        return $this->icon_path === null ? null : Storage::disk('public')->url($this->icon_path);
    }

    /**
     * Whether a deploy of this app snapshots its data first. On unless
     * someone turned it off for the app.
     */
    public function snapshotsBeforeDeploy(string $app): bool
    {
        return (bool) ($this->app_settings[$app]['snapshot_before'] ?? true);
    }

    /**
     * Services ServerOS can back up: the ones it manages (deployed here or
     * adopted), from the latest scan.
     *
     * @return list<array<string, mixed>>
     */
    public function backupableServices(): array
    {
        return array_values(array_filter(
            $this->inventory['services'] ?? [],
            // The daemon says when there's nothing to copy (Docker itself, a
            // proxy); daemons from before it said so leave it out.
            fn ($service) => is_array($service) && isset($service['key']) && ($service['details']['managed'] ?? null) === 'true'
                && ($service['details']['backup'] ?? null) !== 'none',
        ));
    }

    /**
     * Where a port on this machine opens from the panel's own computer, for
     * a local test node (a container enrolled from 127.0.0.1) whose Caddy
     * proxies http://<port>.localhost; see docker/daemon-local/local-proxy.sh.
     * Null for real machines.
     */
    public function localAddress(int $port): ?string
    {
        if (! in_array($this->ip_address, ['127.0.0.1', '::1'], true)) {
            return null;
        }
        // Each local test node has its own proxy port on this Mac.
        $proxyPort = config('daemon.local_node_port');
        foreach (array_filter(explode(',', (string) config('daemon.local_node_ports'))) as $pair) {
            [$host, $nodePort] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($host !== null && $nodePort !== null && $host === ($this->facts['hostname'] ?? null)) {
                $proxyPort = $nodePort;
            }
        }
        if (blank($proxyPort)) {
            return null;
        }

        return "http://{$port}.localhost:{$proxyPort}";
    }

    /**
     * Whether Docker is on the machine, which every deploy needs: true or
     * false once the daemon has reported, null before its first scan. The
     * daemon doesn't always send a version, so a Docker service or any
     * container counts too.
     */
    public function hasDocker(): ?bool
    {
        if (($this->facts['docker_version'] ?? null) !== null) {
            return true;
        }
        if ($this->inventory === null) {
            return null;
        }
        foreach ($this->inventory['services'] ?? [] as $service) {
            $key = (string) ($service['key'] ?? '');
            if (str_starts_with($key, 'docker:') || $key === 'systemd:docker.service') {
                return true;
            }
        }

        return false;
    }
}
