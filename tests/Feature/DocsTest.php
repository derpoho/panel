<?php

use App\Models\DocPage;
use App\Models\DocSection;
use App\Support\Docs\DocsUrl;
use Illuminate\Testing\TestResponse;

function docsUrl(string $path = '/'): string
{
    return 'https://docs.'.config('app.domain').$path;
}

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
    $this->guides = DocSection::query()->create(['title' => 'Guides', 'slug' => 'guides']);
});

/** One JSON-RPC call to the docs' MCP server. */
function docsMcp(string $method, array $params = []): TestResponse
{
    return test()->postJson(docsUrl('/mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

/** A published page in the guides, with a line of text. */
function docsPage(DocSection $section, string $group, int $position, string $slug, string $title, string $body): DocPage
{
    return DocPage::query()->create([
        'doc_section_id' => $section->id, 'group' => $group, 'position' => $position, 'slug' => $slug,
        'title' => $title, 'description' => '', 'body' => $body, 'published' => true,
    ]);
}

test('the docs MCP server searches, reads and lists the published docs, with no sign-in', function () {
    $this->guides->update(['group_order' => ['Getting started', 'Monitoring']]);
    docsPage($this->guides, 'Monitoring', 0, 'monitors', 'Monitors', 'A monitor checks a site and can post to a status page when it goes down.');
    docsPage($this->guides, 'Monitoring', 1, 'status-pages', 'Status pages', 'A status page shows how your services are doing.');
    docsPage($this->guides, 'Monitoring', 2, 'status-incidents', 'Status incidents', 'Post an incident on a status page while something is down.');
    docsPage($this->guides, 'Getting started', 0, 'introduction', 'Introduction', 'What this is.');
    DocPage::query()->where('slug', 'status-pages')->first()->replicate()->forceFill(['slug' => 'secret-plans', 'title' => 'Secret status plans', 'published' => false])->save();

    docsMcp('initialize', ['protocolVersion' => '2025-06-18'])->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'serveros-docs')
        ->assertJsonPath('result.protocolVersion', '2025-06-18');
    expect(collect(docsMcp('tools/list')->json('result.tools'))->pluck('name')->all())->toBe(['search_docs', 'read_doc', 'list_docs']);

    $found = docsMcp('tools/call', ['name' => 'search_docs', 'arguments' => ['query' => 'status page', 'limit' => 3]])
        ->assertOk()->json('result.structuredContent.results');
    expect($found)->toHaveCount(3)
        ->and(collect($found)->pluck('path'))->toContain('guides/status-pages')
        ->and(collect($found)->pluck('path'))->not->toContain('guides/secret-plans');

    // By path, slug or address; drafts don't exist here.
    foreach (['guides/status-pages', 'status-pages', docsUrl('/guides/status-pages.md')] as $page) {
        expect(docsMcp('tools/call', ['name' => 'read_doc', 'arguments' => ['page' => $page]])->json('result.content.0.text'))
            ->toStartWith("# Status pages\n")
            ->toContain('Source: '.DocsUrl::page('guides', 'status-pages'));
    }
    docsMcp('tools/call', ['name' => 'read_doc', 'arguments' => ['page' => 'secret-plans']])->assertJsonPath('result.isError', true);

    $sections = docsMcp('tools/call', ['name' => 'list_docs'])->json('result.structuredContent.sections');
    expect($sections[0]['section'])->toBe('Guides')
        ->and(array_column($sections[0]['groups'], 'group'))->toBe(DocSection::query()->where('slug', 'guides')->sole()->group_order)
        ->and($sections[0]['groups'][0]['pages'][0]['path'])->toBe('guides/introduction');

    docsMcp('tools/call', ['name' => 'delete_everything'])->assertJsonPath('error.code', -32602);
    $this->get(docsUrl('/mcp'))->assertStatus(405);
    $this->postJson(docsUrl('/mcp'), ['nonsense' => true])->assertJsonPath('error.code', -32600);
});
