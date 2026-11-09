<?php

namespace App\Support\Docs;

use App\Models\DocPage;
use App\Models\DocPageTranslation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Translates a docs page with the same Groq model the assistant uses.
 * Code, commands, links and Markdown are kept as they are, and the
 * panel's own words for buttons and pages come from lang/{locale}.json,
 * so the docs say what the screen says.
 */
class DocTranslator
{
    /** @var array<string, string> */
    private const LANGUAGES = ['es' => 'Spanish (Spain), informal tú', 'de' => 'German, informal du', 'hi' => 'Hindi in Devanagari, polite आप, everyday tech words (सर्वर, ऐप, डिप्लॉय) written as Indians say them'];

    public function configured(): bool
    {
        return filled(config('services.groq.translate_key'));
    }

    /** @return list<string> the languages pages are translated into */
    public static function locales(): array
    {
        return array_values(array_filter(array_keys(config('app.locales')), fn ($l) => $l !== 'en' && isset(self::LANGUAGES[$l])));
    }

    public function translate(DocPage $page, string $locale): DocPageTranslation
    {
        $language = self::LANGUAGES[$locale] ?? throw new RuntimeException("No docs translation into {$locale}.");

        $source = ['title' => $page->title, 'description' => (string) $page->description, 'body' => $page->body];
        $out = null;
        // The model sometimes drops a code fence or a link; one more try usually fixes it.
        for ($attempt = 1; $attempt <= 2 && $out === null; $attempt++) {
            $out = $this->ask($source, $language, $this->glossary($page->body, $locale));
            // A second read, as a native speaker would: grammar and any English left behind.
            $out = $out === null ? null : ($this->ask($out, $language, [], proofread: true) ?? $out);
            if ($out !== null && ! $this->keepsStructure($page->body, $out['body'])) {
                $out = null;
            }
        }
        if ($out === null) {
            throw new RuntimeException("Couldn’t translate “{$page->title}” into {$locale}: the result lost code or links.");
        }

        return DocPageTranslation::query()->updateOrCreate(
            ['doc_page_id' => $page->id, 'locale' => $locale],
            [
                'title' => $out['title'],
                'description' => $out['description'] !== '' ? $out['description'] : null,
                'body' => $out['body'],
                'source_hash' => $page->sourceHash(),
            ],
        );
    }

    /**
     * @param  array{title: string, description: string, body: string}  $source
     * @param  array<string, string>  $glossary
     * @return array{title: string, description: string, body: string}|null
     */
    private function ask(array $source, string $language, array $glossary, bool $proofread = false): ?array
    {
        $rules = $proofread ? implode("\n", [
            "This is documentation for ServerOS, a server management panel, translated into {$language}. Proofread it as a native speaker.",
            'Fix grammar (gender, articles, verb forms), awkward wording and any English left outside code, URLs and product names. Change nothing else.',
            'Keep the Markdown, code, links and line breaks exactly as they are. Do not use em dashes.',
            'Reply with the same JSON object: title, description, body.',
        ]) : implode("\n", [
            "Translate this documentation page for ServerOS, a server management panel, into {$language}.",
            'Reply with a JSON object with the same keys: title, description, body.',
            'body is Markdown: keep every heading, list, table, link target, image, HTML tag and blank line exactly where it is.',
            'Never translate anything inside code fences or `inline code`, URLs, file paths, commands, environment variables or product names (ServerOS, Docker, Caddy, GitHub and so on).',
            'Translate every sentence, heading, bold term and image alt text: no English may be left outside code and names. Keep grammar right around bold and glossary terms (gender and articles, e.g. "una **máquina**", verb forms matching tú/du). Read it back as a native speaker before answering.',
            'Write plainly and casually, as the English does. Do not use em dashes.',
            $glossary === [] ? '' : "When the page names these panel buttons and pages, use the panel's own words:\n".json_encode($glossary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);

        try {
            $response = Http::withToken((string) config('services.groq.translate_key'))
                ->connectTimeout(10)
                ->timeout(180)
                ->retry(4, fn (int $attempt, \Throwable $e) => $e instanceof RequestException && $e->response->status() === 429
                    ? 1000 * max(5, (int) $e->response->header('Retry-After'))
                    : 2000, throw: true)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $model = (string) config('services.groq.translate_model'),
                    'temperature' => 0.1,
                    ...(str_starts_with($model, 'openai/gpt-oss') ? ['reasoning_effort' => 'medium', 'include_reasoning' => false] : []),
                    'response_format' => ['type' => 'json_object'],
                    'max_completion_tokens' => 12000,
                    'messages' => [
                        ['role' => 'system', 'content' => $rules],
                        ['role' => 'user', 'content' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ],
                ]);
        } catch (RequestException $e) {
            throw new RuntimeException('Groq refused the translation: '.$e->response->status(), previous: $e);
        }

        $data = json_decode((string) $response->json('choices.0.message.content'), true);
        if (! is_array($data) || ! is_string($data['title'] ?? null) || ! is_string($data['body'] ?? null)) {
            return null;
        }

        return ['title' => trim($data['title']), 'description' => trim(is_string($data['description'] ?? null) ? $data['description'] : ''), 'body' => $data['body']];
    }

    /**
     * Panel words the page mentions in bold, with how the panel says them
     * in that language.
     *
     * @return array<string, string>
     */
    private function glossary(string $body, string $locale): array
    {
        // lang/{locale}.json with the packages' own strings, as __() has them.
        $lines = app('translator')->getLoader()->load($locale, '*', '*');

        preg_match_all('/\*\*([^*\n]{2,60})\*\*/u', $body, $matches);
        $glossary = [];
        foreach (array_unique($matches[1]) as $words) {
            foreach (preg_split('/\s*›\s*/u', $words) ?: [] as $part) {
                if (is_string($lines[$part] ?? null)) {
                    $glossary[$part] = $lines[$part];
                }
            }
        }

        return $glossary;
    }

    /** Same code fences, inline code and link targets as the English. */
    private function keepsStructure(string $english, string $translated): bool
    {
        $count = fn (string $text, string $pattern) => preg_match_all($pattern, $text);
        if ($count($english, '/^```/m') !== $count($translated, '/^```/m')) {
            return false;
        }
        preg_match_all('/\]\(([^)\s]+)/', $english, $links);
        foreach ($links[1] as $target) {
            if (! str_contains($translated, $target)) {
                return false;
            }
        }
        preg_match_all('/```.*?```/s', $english, $blocks);
        foreach ($blocks[0] as $block) {
            if (! str_contains($translated, $block)) {
                return false;
            }
        }

        return true;
    }
}
