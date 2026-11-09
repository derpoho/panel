<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a workspace's backups go: an S3-compatible bucket ("s3"), or one
 * of its machines that receives them over SFTP ("machine").
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $type
 * @property array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, path_style: bool, prefix: string}|null $settings
 * @property int|null $machine_id
 * @property string|null $private_key
 * @property string|null $public_key
 * @property string|null $host_key
 * @property string $status ready, setting_up or failed
 * @property string|null $status_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Machine|null $machine
 */
#[Fillable(['name', 'type', 'settings', 'machine_id'])]
#[Hidden(['settings', 'private_key'])]
class BackupDestination extends Model
{
    public const array TYPES = ['s3', 'machine'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
            'private_key' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return HasMany<Machine, $this> */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /** Where it points, for lists: "s3://bucket" or the receiving machine. */
    public function describe(): string
    {
        return $this->type === 's3'
            ? 's3://'.($this->settings['bucket'] ?? '').(filled($this->settings['prefix'] ?? null) ? '/'.trim($this->settings['prefix'], '/') : '')
            : ($this->machine->name ?? 'Removed machine');
    }

    /**
     * The destination as the daemon's BackupTo job takes it.
     *
     * @return array<string, mixed>
     */
    public function forDaemon(): array
    {
        if ($this->type === 's3') {
            $s = $this->settings ?? [];

            return [
                'type' => 's3',
                'endpoint' => $s['endpoint'] ?? '',
                'region' => $s['region'] ?? '',
                'bucket' => $s['bucket'] ?? '',
                'access_key' => $s['access_key'] ?? '',
                'secret_key' => $s['secret_key'] ?? '',
                'path_style' => (bool) ($s['path_style'] ?? false),
            ];
        }

        return [
            'type' => 'sftp',
            'host' => $this->machine?->ip_address,
            'port' => 22,
            'user' => 'serveros-backups',
            'private_key' => $this->private_key,
            'host_key' => $this->host_key,
        ];
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
            'where' => $this->describe(),
            'machineId' => $this->machine_id,
            'status' => $this->status,
            'statusMessage' => $this->status_message,
            // Never the keys; enough to edit the rest.
            'settings' => $this->type === 's3' ? [
                'endpoint' => $this->settings['endpoint'] ?? '',
                'region' => $this->settings['region'] ?? '',
                'bucket' => $this->settings['bucket'] ?? '',
                'prefix' => $this->settings['prefix'] ?? '',
                'pathStyle' => (bool) ($this->settings['path_style'] ?? false),
                'accessKey' => $this->settings['access_key'] ?? '',
            ] : null,
        ];
    }
}
