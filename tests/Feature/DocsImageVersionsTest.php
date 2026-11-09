<?php

use App\Support\Docs\DocsMarkdown;

test('our own images get a version from their contents, so a retaken screenshot is never served from a cache', function () {
    $html = DocsMarkdown::render('![Logo](/images/email/logo.png)');
    $version = substr((string) md5_file(public_path('images/email/logo.png')), 0, 8);

    expect($html)->toContain('src="/images/email/logo.png?v='.$version.'"');
});

test('other images are left as they are', function () {
    expect(DocsMarkdown::render('![x](https://example.com/a.png)'))->toContain('src="https://example.com/a.png"')
        ->and(DocsMarkdown::render('![x](/images/docs/missing.webp)'))->toContain('src="/images/docs/missing.webp"')
        ->and(DocsMarkdown::render('![x](/images/email/logo.png?v=1)'))->toContain('src="/images/email/logo.png?v=1"')
        ->and(DocsMarkdown::render('![x](//cdn.example.com/a.png)'))->toContain('src="//cdn.example.com/a.png"');
});
