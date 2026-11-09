<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document staff send to customers from /admin, such as the DPA or SLA:
 * the PDF's title and Markdown text, and the email that goes with it when
 * it's sent on its own. All of it can hold placeholders like {{ company }}
 * (see DocumentFields), filled in from the workspace when it's sent.
 *
 * @property int $id
 * @property string $name
 * @property string $kind dpa, sla, security or other
 * @property string|null $description
 * @property string $title
 * @property string $body Markdown
 * @property string $email_subject
 * @property string $email_body Markdown
 * @property int|null $updated_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $updatedBy
 */
class DocumentTemplate extends Model
{
    public const KINDS = [
        'dpa' => 'Data processing',
        'sla' => 'Service level',
        'security' => 'Security and compliance',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
