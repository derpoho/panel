<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line a service on a machine wrote, as its daemon shipped it.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $service_key
 * @property string $service the container's or unit's name
 * @property int $logged_at unix milliseconds
 * @property string $level error, warn, info or debug
 * @property string $stream stdout, stderr or journal
 * @property string $message
 * @property-read Machine $machine
 */
class LogEntry extends Model
{
    public const LEVELS = ['error', 'warn', 'info', 'debug'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'logged_at' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * @return array{id: int, machineId: int, service: string, serviceKey: string, at: int, level: string, stream: string, message: string}
     */
    public function toPageProps(): array
    {
        return [
            'id' => $this->id,
            'machineId' => $this->machine_id,
            'service' => $this->service,
            'serviceKey' => $this->service_key,
            'at' => $this->logged_at,
            'level' => $this->level,
            'stream' => $this->stream,
            'message' => $this->message,
        ];
    }
}
