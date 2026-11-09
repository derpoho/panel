<?php

use App\Models\DocPage;
use App\Models\DocPageTranslation;
use App\Models\DocSection;
use App\Support\Docs\DocTranslator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
    $guides = DocSection::query()->create(['title' => 'Guides', 'slug' => 'guides']);
    $this->page = DocPage::query()->create(['doc_section_id' => $guides->id, 'group' => 'Basics', 'title' => 'Intro', 'slug' => 'intro', 'body' => "## Hello\n\nRun `serverosd`.\n\n```bash\ncurl x\n```", 'published' => true]);
});

test('the translator keeps code and stores what it made', function () {
    config()->set('services.groq.translate_key', 'test');
    $translated = ['title' => 'Introducción', 'description' => '', 'body' => "## Hola\n\nEjecuta `serverosd`.\n\n```bash\ncurl x\n```"];
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($translated)]]]])]);

    $translation = app(DocTranslator::class)->translate($this->page, 'es');

    expect($translation->title)->toBe('Introducción')
        ->and($translation->source_hash)->toBe($this->page->sourceHash());
});

test('a translation that drops a code block is refused', function () {
    config()->set('services.groq.translate_key', 'test');
    Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['title' => 'X', 'description' => '', 'body' => '## Hola'])]]]])]);

    expect(fn () => app(DocTranslator::class)->translate($this->page, 'es'))->toThrow(RuntimeException::class);
    expect(DocPageTranslation::query()->count())->toBe(0);
});
