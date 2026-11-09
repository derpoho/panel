<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One update on a status page incident: where it stands, in words.
 *
 * @property int $id
 * @property int $status_incident_id
 * @property string $state investigating, identified, monitoring or resolved
 * @property string $message
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StatusIncident $incident
 */
class StatusIncidentUpdate extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<StatusIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusIncident::class, 'status_incident_id');
    }
}
