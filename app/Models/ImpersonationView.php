<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A page an admin opened while viewing as someone: the route and its
 * parameters, never what the page showed.
 *
 * @property int $id
 * @property int $impersonation_session_id
 * @property string $route
 * @property array<string, string|null>|null $parameters raw route parameters; null where one wasn't plain text
 * @property CarbonImmutable $created_at
 */
class ImpersonationView extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('View as pages are never edited.'));
        static::deleting(fn () => throw new LogicException('View as pages are only pruned.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['parameters' => 'array', 'created_at' => 'datetime'];
    }
}
