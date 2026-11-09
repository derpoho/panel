<?php

namespace App\Mcp\Docs;

use App\Mcp\McpServer;
use App\Models\DocPage;
use App\Models\DocSection;
use App\Support\Docs\DocsSearch;
use App\Support\Docs\DocsUrl;

/**
 * The docs as an MCP server, at https://docs.serveros.com/mcp: search,
 * read and list the published pages, for any AI app. Open to anyone and
 * read-only, like the docs site; it can't touch a workspace (that's the
 * workspace's own server). The protocol is the workspace server's.
 */
final class DocsMcpServer
{
    public const NAME = 'serveros-docs';

    public function __construct(private readonly DocsSearch $search) {}

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public function handle(mixed $payload): ?array
    {
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $answers = array_values(array_filter(array_map(fn ($message) => $this->message($message), $payload)));

            return $answers === [] ? null : $answers;
        }

        return $this->message($payload);
    }

    /** @return list<array<string, mixed>> */
    public static function tools(): array
    {
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'search_docs',
                'title' => 'Search the docs',
                'description' => 'Search the ServerOS docs. Returns the best matching pages, best first, each with its path for read_doc.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'What to look for, in plain words: "deploy from GitHub", "status page custom domain".'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'How many pages, 5 if left out.'],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'read_doc',
                'title' => 'Read a docs page',
                'description' => 'Read one ServerOS docs page as Markdown.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'string', 'description' => 'The page: its path ("guides/status-pages"), its slug ("status-pages") or its address on docs.serveros.com.'],
                    ],
                    'required' => ['page'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_docs',
                'title' => 'List the docs',
                'description' => 'Every ServerOS docs page, by section and group, with a line on each and its path for read_doc.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
                'annotations' => $readOnly,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function message(mixed $message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return is_array($message) && (isset($message['result']) || isset($message['error']))
                ? null
                : McpServer::error($message['id'] ?? null, -32600, 'Invalid request.');
        }
        if (! array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method']) {
            'initialize' => McpServer::result($id, $this->initialize($params)),
            'ping' => McpServer::result($id, new \stdClass),
            'tools/list' => McpServer::result($id, ['tools' => self::tools()]),
            'tools/call' => $this->call($id, $params),
            'resources/list' => McpServer::result($id, ['resources' => []]),
            'resources/templates/list' => McpServer::result($id, ['resourceTemplates' => []]),
            'prompts/list' => McpServer::result($id, ['prompts' => []]),
            default => McpServer::error($id, -32601, "Method not found: {$message['method']}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : null;

        return [
            'protocolVersion' => in_array($asked, McpServer::VERSIONS, true) ? $asked : McpServer::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::NAME, 'title' => 'ServerOS docs', 'version' => McpServer::VERSION],
            'instructions' => 'The ServerOS docs: how to connect servers ("machines"), deploy to them, monitor them, back them up and run it all from AI apps or the API. Search with search_docs, then read_doc the best page before answering, and link the page you used. To act on someone\'s servers, they need their workspace\'s own MCP server (see read_doc "ai-apps").',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $answer = match ($name) {
            'search_docs' => $this->searchDocs($arguments),
            'read_doc' => $this->readDoc($arguments),
            'list_docs' => $this->listDocs(),
            default => null,
        };
        if ($answer === null) {
            return McpServer::error($id, -32602, "Unknown tool: {$name}");
        }

        return McpServer::result($id, $answer);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function searchDocs(array $arguments): array
    {
        $query = is_string($arguments['query'] ?? null) ? mb_substr(trim($arguments['query']), 0, 200) : '';
        if ($query === '') {
            return McpServer::content(['error' => 'Say what to look for in query.'], true);
        }
        $limit = max(1, min(10, (int) ($arguments['limit'] ?? 5)));

        $pages = $this->search->search($query, $limit)->map(fn (DocPage $page) => self::summary($page))->values()->all();

        return McpServer::content($pages === []
            ? ['results' => [], 'note' => 'Nothing matched. Try other words, or list_docs to see every page.']
            : ['results' => $pages], false);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function readDoc(array $arguments): array
    {
        $asked = is_string($arguments['page'] ?? null) ? trim($arguments['page']) : '';
        // An address, a path or a slug: the last two parts are enough.
        $path = trim((string) preg_replace('#\.md$#', '', (string) (parse_url($asked, PHP_URL_PATH) ?? $asked)), '/');
        $parts = $path === '' ? [] : explode('/', $path);
        $slug = (string) end($parts);
        $section = count($parts) > 1 ? $parts[count($parts) - 2] : null;

        $page = DocPage::query()->where('published', true)->where('slug', $slug)
            ->when($section !== null, fn ($query) => $query->whereHas('section', fn ($q) => $q->where('slug', $section)))
            ->with('section')->first();
        if ($page === null) {
            return McpServer::content(['error' => "No docs page at \"{$asked}\". Use search_docs or list_docs to find one."], true);
        }

        $text = "# {$page->title}\n\n".(filled($page->description) ? "> {$page->description}\n\n" : '')
            .'Source: '.DocsUrl::page($page->section->slug, $page->slug)."\n\n".trim($page->body)."\n";

        return McpServer::content($text, false);
    }

    /** @return array<string, mixed> */
    private function listDocs(): array
    {
        $sections = DocSection::query()->orderBy('position')->orderBy('id')->get()->map(function (DocSection $section) {
            $order = array_flip($section->group_order ?? []);
            $groups = $section->pages()->where('published', true)->get()->each->setRelation('section', $section)
                ->groupBy('group')
                ->sortBy(fn ($pages, $name) => [$order[$name] ?? PHP_INT_MAX, $pages->min('id')])
                ->map(fn ($pages, $name) => [
                    'group' => $name,
                    'pages' => $pages->map(fn (DocPage $page) => self::summary($page, false))->values()->all(),
                ])->values()->all();

            return ['section' => $section->title, 'groups' => $groups];
        })->filter(fn (array $section) => $section['groups'] !== [])->values()->all();

        return McpServer::content(['sections' => $sections], false);
    }

    /** @return array<string, string|null> */
    private static function summary(DocPage $page, bool $placed = true): array
    {
        return array_filter([
            'title' => $page->title,
            'description' => $page->description,
            'section' => $placed ? $page->section->title : null,
            'group' => $placed ? $page->group : null,
            'path' => $page->section->slug.'/'.$page->slug,
            'url' => DocsUrl::page($page->section->slug, $page->slug),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
