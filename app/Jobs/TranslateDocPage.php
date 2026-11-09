<?php

namespace App\Jobs;

use App\Models\DocPage;
use App\Support\Docs\DocTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings a docs page's translations up to date after it's saved in
 * /admin/docs. Until it's done, readers get the English.
 */
class TranslateDocPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 900;

    public function __construct(public int $pageId) {}

    public function handle(DocTranslator $translator): void
    {
        $page = DocPage::query()->find($this->pageId);
        if ($page === null || ! $page->published || ! $translator->configured()) {
            return;
        }

        $hash = $page->sourceHash();
        foreach (DocTranslator::locales() as $locale) {
            if ($page->translations()->where('locale', $locale)->where('source_hash', $hash)->doesntExist()) {
                $translator->translate($page, $locale);
            }
        }
    }
}
