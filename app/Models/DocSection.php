<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tab along the top of the docs site, holding its own sidebar of pages.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property int $position
 * @property list<string>|null $group_order the sidebar's groups, in order; others follow
 * @property array<string, string>|null $group_descriptions what each group covers, for the docs home
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['title', 'slug', 'position', 'group_order', 'group_descriptions'])]
class DocSection extends Model
{
    protected function casts(): array
    {
        return ['group_order' => 'array', 'group_descriptions' => 'array'];
    }

    /** @return HasMany<DocPage, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(DocPage::class)->orderBy('position')->orderBy('id');
    }
}
