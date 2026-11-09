<?php

namespace App\Models;

use App\Support\Machines\MachineMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a workspace decides its machines do: whether the daemon
 * looks for what's running, sends logs (and how IPs in them are masked),
 * runs the security watch, and installs updates on its own; and whether a
 * machine's new status page shows services as they're found.
 *
 * A machine follows its own pick, else its group's, else the workspace's
 * default (MachinePolicies::for). Health (CPU, memory, disk, network) is
 * always sent: it's how ServerOS knows a machine is up.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property bool $is_default
 * @property bool $discovery
 * @property bool $logs
 * @property string $log_ip_masking off, partial or hash
 * @property bool $security_watch
 * @property bool $error_reports whether the daemon sends its crash and error reports (scrubbed)
 * @property bool $status_page_new
 * @property string $updates automatic, approve or pinned
 * @property string|null $pinned_version the daemon release machines stay on, with updates pinned
 * @property list<array{field: string, value: string}>|null $applies_to machines it applies to by their metadata (any of these)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class MachinePolicy extends Model
{
    /** @var list<string> */
    public const MASKING = ['off', 'partial', 'hash'];

    /** @var list<string> */
    public const UPDATES = ['automatic', 'approve', 'pinned'];

    /** The settings a policy holds, as the forms and the API name them. */
    public const SETTINGS = ['discovery', 'logs', 'log_ip_masking', 'security_watch', 'error_reports', 'status_page_new', 'updates'];

    /**
     * Where a new workspace starts, from what the welcome flow learned: a
     * hosting company sends no logs and approves each daemon update, the
     * rest get everything with updates on their own. Nothing is added to a
     * status page by itself for anyone.
     *
     * @var array<string, array<string, bool|string|null>>
     */
    public const PRESETS = [
        'own' => ['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'automatic', 'pinned_version' => null],
        'agency' => ['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'automatic', 'pinned_version' => null],
        'hosting' => ['discovery' => true, 'logs' => false, 'log_ip_masking' => 'partial', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'approve', 'pinned_version' => null],
        'exploring' => ['discovery' => true, 'logs' => true, 'log_ip_masking' => 'off', 'security_watch' => true, 'error_reports' => true, 'status_page_new' => false, 'updates' => 'automatic', 'pinned_version' => null],
    ];

    protected $guarded = ['id', 'workspace_id'];

    protected $attributes = [
        'is_default' => false,
        'discovery' => true,
        'logs' => true,
        'log_ip_masking' => 'off',
        'security_watch' => true,
        'error_reports' => true,
        'status_page_new' => false,
        'updates' => 'automatic',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'discovery' => 'boolean',
            'logs' => 'boolean',
            'security_watch' => 'boolean',
            'error_reports' => 'boolean',
            'status_page_new' => 'boolean',
            'applies_to' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Machines that picked this policy themselves.
     *
     * @return HasMany<Machine, $this>
     */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /** @return HasMany<MachineGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(MachineGroup::class);
    }

    /**
     * The preset for a welcome answer, or the usual one.
     *
     * @return array<string, bool|string|null>
     */
    public static function preset(?string $segment): array
    {
        return self::PRESETS[$segment] ?? self::PRESETS['own'];
    }

    /**
     * The settings on their own, as forms and the API take them.
     *
     * @return array{discovery: bool, logs: bool, log_ip_masking: string, security_watch: bool, error_reports: bool, status_page_new: bool, updates: string, pinned_version: string|null}
     */
    public function settings(): array
    {
        return [
            'discovery' => $this->discovery,
            'logs' => $this->logs,
            'log_ip_masking' => $this->log_ip_masking,
            'security_watch' => $this->security_watch,
            'error_reports' => $this->error_reports,
            'status_page_new' => $this->status_page_new,
            'updates' => $this->updates,
            'pinned_version' => $this->updates === 'pinned' ? $this->pinned_version : null,
        ];
    }

    /**
     * What the daemon is told, in `reconfigure` and with its enrolment.
     * Daemons that can't do one of these skip it (see
     * DaemonJobQueue::canFollowPolicy).
     *
     * @return array{discovery: bool, logs: bool, log_ip_masking: string, security_watch: bool, error_reports: bool, updates_automatic: bool, pinned_version: string|null}
     */
    public function toDaemon(): array
    {
        return [
            'discovery' => $this->discovery,
            'logs' => $this->logs,
            'log_ip_masking' => $this->log_ip_masking,
            'security_watch' => $this->security_watch,
            'error_reports' => $this->error_reports,
            'updates_automatic' => $this->updates === 'automatic',
            // A pin holds even against an approved update; null lifts it.
            'pinned_version' => $this->updates === 'pinned' ? $this->pinned_version : null,
        ];
    }

    /**
     * Whether its rules pick this machine: any of its field/value pairs.
     */
    public function appliesTo(Machine $machine): bool
    {
        foreach ($this->applies_to ?? [] as $rule) {
            if (MachineMetadata::matches($machine, $rule['field'], $rule['value'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isDefault' => $this->is_default,
            ...$this->settings(),
            'appliesTo' => $this->applies_to ?? [],
        ];
    }
}
