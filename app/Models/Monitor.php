<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An uptime monitor: a URL (or a host and port) the panel checks on a
 * schedule. Two failed checks in a row open an incident and alert; the
 * first passing check closes it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $machine_id
 * @property string $name
 * @property string $type http or tcp
 * @property string|null $url
 * @property string|null $host
 * @property int|null $port
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property int|null $expected_status null for any 2xx or 3xx
 * @property string|null $keyword
 * @property bool $paused
 * @property bool $alerts
 * @property string $status pending, up or down
 * @property int $failures
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $last_checked_at
 * @property int|null $last_response_ms
 * @property string|null $last_error
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $ssl_expires_at
 * @property CarbonImmutable|null $ssl_checked_at
 * @property CarbonImmutable|null $ssl_alerted_at
 * @property array<string, array{0: int, 1: int, 2: int}>|null $daily
 * @property CarbonImmutable|null $muted_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Machine|null $machine
 */
class Monitor extends Model
{
    public const TYPES = ['http', 'tcp'];

    public const INTERVALS = [30, 60, 180, 300, 600, 1800];

    /** Failed checks in a row before it counts as down. */
    public const CONFIRM_AFTER = 2;

    public const DAYS = 90;

    /** Days before a certificate expires that it alerts. */
    public const SSL_WARN_DAYS = 14;

    protected $guarded = ['id', 'workspace_id', 'daily'];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
            'alerts' => 'boolean',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
            'ssl_alerted_at' => 'datetime',
            'muted_until' => 'datetime',
            'daily' => 'array',
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
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * @return HasMany<MonitorIncident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class);
    }

    /**
     * @return HasOne<MonitorIncident, $this>
     */
    public function openIncident(): HasOne
    {
        return $this->hasOne(MonitorIncident::class)->whereNull('resolved_at')->latestOfMany('started_at');
    }

    /**
     * Its alerts are held: muted itself, or its machine is in maintenance.
     */
    public function muted(): bool
    {
        return ($this->muted_until !== null && $this->muted_until->isFuture())
            || ($this->machine_id !== null && $this->machine?->inMaintenance());
    }

    /** What it checks, as people write it. */
    public function target(): string
    {
        return $this->type === 'tcp' ? "{$this->host}:{$this->port}" : (string) $this->url;
    }

    public function isHttps(): bool
    {
        return $this->type === 'http' && str_starts_with(strtolower((string) $this->url), 'https://');
    }

    /**
     * Count one check into its day, dropping days past the window.
     */
    public function recordDay(bool $ok, ?int $ms, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $daily = $this->daily ?? [];
        $day = $at->toDateString();
        [$checks, $passed, $total] = $daily[$day] ?? [0, 0, 0];
        $daily[$day] = [$checks + 1, $passed + ($ok ? 1 : 0), $total + ($ok ? (int) $ms : 0)];

        $cutoff = $at->copy()->subDays(self::DAYS - 1)->toDateString();
        $daily = array_filter($daily, fn (string $d) => $d >= $cutoff, ARRAY_FILTER_USE_KEY);
        ksort($daily);

        $this->daily = $daily;
    }

    /**
     * Uptime over the last `days` days, as a percentage, or null before
     * any check.
     */
    public function uptime(int $days): ?float
    {
        $cutoff = now()->subDays($days - 1)->toDateString();
        $checks = 0;
        $ok = 0;

        foreach ($this->daily ?? [] as $day => [$c, $o]) {
            if ($day >= $cutoff) {
                $checks += $c;
                $ok += $o;
            }
        }

        return $checks === 0 ? null : round($ok / $checks * 100, 3);
    }

    /**
     * Each of the last `days` days: its uptime percentage, or null.
     *
     * @return list<array{day: string, percent: float|null}>
     */
    public function days(int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            [$checks, $ok] = $this->daily[$day] ?? [0, 0];
            $out[] = ['day' => $day, 'percent' => $checks === 0 ? null : round($ok / $checks * 100, 2)];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'url' => $this->url,
            'host' => $this->host,
            'port' => $this->port,
            'target' => $this->target(),
            'machineId' => $this->machine_id,
            'interval' => $this->interval_seconds,
            'timeout' => $this->timeout_seconds,
            'expectedStatus' => $this->expected_status,
            'keyword' => $this->keyword,
            'paused' => $this->paused,
            'alerts' => $this->alerts,
            'status' => $this->paused ? 'paused' : $this->status,
            'since' => $this->status_changed_at?->toIso8601String(),
            'lastCheckedAt' => $this->last_checked_at?->toIso8601String(),
            'responseMs' => $this->last_response_ms,
            'error' => $this->last_error,
            'sslExpiresAt' => $this->ssl_expires_at?->toIso8601String(),
            'mutedUntil' => $this->muted_until?->isFuture() ? $this->muted_until->toIso8601String() : null,
            'uptime30' => $this->uptime(30),
            'days' => array_column($this->days(30), 'percent'),
        ];
    }
}
