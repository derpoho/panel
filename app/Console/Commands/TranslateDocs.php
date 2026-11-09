<?php

namespace App\Console\Commands;

use App\Models\DocPage;
use App\Support\Docs\DocTranslator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Translates published docs pages that have no up-to-date translation
 * (or all of them, with --force). Saving a page in /admin/docs does the
 * same for that page on the queue.
 */
class TranslateDocs extends Command
{
    protected $signature = 'docs:translate {--locale=* : Only these languages} {--page=* : Only these page slugs} {--force : Translate again even if up to date}';

    protected $description = 'Translate the docs into the site’s other languages';

    public function handle(DocTranslator $translator): int
    {
        if (! $translator->configured()) {
            $this->error('GROQ_TRANSLATE_KEY (or GROQ_API_KEY) isn’t set.');

            return self::FAILURE;
        }

        $locales = $this->option('locale') ?: DocTranslator::locales();
        $pages = DocPage::query()->where('published', true)
            ->when($this->option('page'), fn ($query, $slugs) => $query->whereIn('slug', $slugs))
            ->with('translations')->orderBy('id')->get();

        $failed = 0;
        foreach ($pages as $page) {
            foreach ($locales as $locale) {
                $current = $page->translations->firstWhere('locale', $locale);
                if (! $this->option('force') && $current?->source_hash === $page->sourceHash()) {
                    continue;
                }
                try {
                    $translator->translate($page, $locale);
                    $this->line("  {$locale}  {$page->slug}");
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("  {$locale}  {$page->slug}: {$e->getMessage()}");
                }
            }
        }

        $this->info($failed === 0 ? 'Docs translated.' : "{$failed} couldn’t be translated; run it again to retry them.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
