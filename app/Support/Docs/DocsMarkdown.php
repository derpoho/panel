<?php

namespace App\Support\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Turns a docs page's Markdown into HTML: GitHub-flavoured (tables, task
 * lists, strikethrough, autolinks), headings get ids for the "On this page"
 * list, and raw HTML or unsafe links in the source are dropped. Our own
 * images get ?v= with a hash of the file, so a retaken screenshot with the
 * same name never shows from a cache as the old one.
 */
class DocsMarkdown
{
    private static ?MarkdownConverter $converter = null;

    /** @var array<string, string|null> each image path's version, once worked out */
    private static array $versions = [];

    public static function render(string $markdown): string
    {
        return (string) self::converter()->convert($markdown);
    }

    /**
     * /images/docs/home.webp becomes /images/docs/home.webp?v=97a2b276 when
     * the file is ours, in public/; anything else is left as it is.
     */
    public static function versioned(string $url): string
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '?') || str_contains($url, '..')) {
            return $url;
        }

        if (! array_key_exists($url, self::$versions)) {
            $file = public_path(ltrim($url, '/'));
            $hash = is_file($file) ? md5_file($file) : false;
            self::$versions[$url] = $hash === false ? null : substr($hash, 0, 8);
        }

        return self::$versions[$url] === null ? $url : $url.'?v='.self::$versions[$url];
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'heading_permalink' => [
                'insert' => 'none',
                'id_prefix' => '',
                'fragment_prefix' => '',
                'apply_id_to_heading' => true,
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof Image) {
                    $node->setUrl(self::versioned($node->getUrl()));
                }
            }
        });

        return self::$converter = new MarkdownConverter($environment);
    }
}
