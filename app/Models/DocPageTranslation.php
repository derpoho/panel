<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A docs page in another language. Made from the English by
 * docs:translate; source_hash says which English it was made from, so an
 * edited page falls back to English until it's translated again.
 *
 * @property int $id
 * @property int $doc_page_id
 * @property string $locale
 * @property string $title
 * @property string|null $description
 * @property string $body Markdown
 * @property string $source_hash
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['doc_page_id', 'locale', 'title', 'description', 'body', 'source_hash'])]
class DocPageTranslation extends Model
{
    /** @return BelongsTo<DocPage, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(DocPage::class, 'doc_page_id');
    }
}
