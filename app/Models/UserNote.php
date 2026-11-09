<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something staff wrote down about a person, on their admin page. Never
 * shown to them, nor anywhere outside /admin.
 *
 * @property int $id
 * @property int $user_id who it's about
 * @property int|null $author_id
 * @property string|null $author_name as they were called when they wrote it
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User|null $author
 */
class UserNote extends Model
{
    public const int MAX_LENGTH = 2000;

    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
