<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;

/**
 * The template library behind the dashboard's "Deploy service" dialog:
 * ready-made stacks (databases, self-hosted apps, game servers) and app
 * frameworks that build the person's own repository.
 */
final class ListTemplates extends Tool
{
    public function __construct(private readonly ServiceTemplateCatalog $catalog) {}

    public function name(): string
    {
        return 'list_templates';
    }

    public function title(): string
    {
        return 'List deploy templates';
    }

    public function description(): string
    {
        return 'Lists the service templates deploy_template can deploy, as the dashboard’s “Deploy service” library shows them. '
            .'kind "stack" is ready-made (a database, a self-hosted app, a game server: no code needed); kind "app" builds the person’s own public Git repository with a Dockerfile ServerOS provides (Laravel, Next.js, Django…; "custom-dockerfile" builds the repository’s own Dockerfile). '
            .'Each row has the key to deploy, its category, whether it’s a web service (web services can get a domain with automatic HTTPS), the port it listens on and whether that can be changed, and `needs`: what deploy_template must be given beyond a machine. '
            .'Filter with query (words matched against name, key, description and category, like the dialog’s search), category or kind. '
            .'Pass template (a key) to get one template in full: every setting with its label, hint and default, which are secrets, which are generated when left empty (passwords, keys: shown once after deploying), which must be accepted (a licence: ask the person, never accept for them), the health check path and notes. '
            .'To pick a template for a repository, use detect_repository.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'template' => Schema::string('A template key (like postgres or laravel) to get it in full, with all its settings.', maxLength: 100),
            'query' => Schema::string('Words to search for, like "postgres" or "wiki".', maxLength: 200),
            'category' => Schema::string('Only this category.', ServiceTemplate::CATEGORIES),
            'kind' => Schema::string('Only ready-made stacks, or only app frameworks that build a repository.', ['stack', 'app']),
            'limit' => Schema::integer('How many to return (1–150, default 50).', 1, 150),
            'offset' => Schema::integer('Skip this many, to page through (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'template' => ['sometimes', 'string', 'max:100'],
            'query' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', ServiceTemplate::CATEGORIES)],
            'kind' => ['sometimes', 'string', 'in:stack,app'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:150'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (isset($data['template'])) {
            $template = $this->catalog->find(trim($data['template']));
            if ($template === null) {
                throw new ToolError("No template “{$data['template']}”. list_templates without template lists them.");
            }

            return ['template' => self::full($template)];
        }

        $words = preg_split('/\s+/', mb_strtolower(trim((string) ($data['query'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = array_values(array_filter($this->catalog->all(), function (ServiceTemplate $t) use ($data, $words) {
            if (isset($data['category']) && $t->category !== $data['category']) {
                return false;
            }
            if (isset($data['kind']) && ($t->isApp() ? 'app' : 'stack') !== $data['kind']) {
                return false;
            }
            $haystack = mb_strtolower("{$t->name} {$t->slug} {$t->description} {$t->category}");
            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        }));

        $offset = (int) ($data['offset'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);
        $page = array_slice($matches, $offset, $limit);

        return [
            'total' => count($matches),
            'offset' => $offset,
            'more' => $offset + count($page) < count($matches),
            'categories' => ServiceTemplate::CATEGORIES,
            'templates' => array_map(fn (ServiceTemplate $t) => self::row($t), $page),
        ];
    }

    /** @return array<string, mixed> */
    public static function row(ServiceTemplate $template): array
    {
        return [
            'key' => $template->slug,
            'name' => $template->name,
            'category' => $template->category,
            'kind' => $template->isApp() ? 'app' : 'stack',
            'description' => $template->description,
            'web' => $template->web,
            'port' => $template->port,
            'portEditable' => $template->portEditable,
            'needs' => self::needs($template),
        ];
    }

    /**
     * Everything about a template deploy_template can be given.
     *
     * @return array<string, mixed>
     */
    public static function full(ServiceTemplate $template): array
    {
        return [
            ...self::row($template),
            'healthPath' => $template->health,
            'notes' => $template->notesFor(null),
            // As the dialog shows them: generated values are made at deploy time, never listed.
            'settings' => array_map(function (array $field): array {
                $default = (string) ($field['default'] ?? '');
                $generated = str_starts_with($default, '{');

                return [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'hint' => $field['hint'] ?? null,
                    'secret' => (bool) ($field['secret'] ?? false),
                    'required' => (bool) ($field['required'] ?? false),
                    'accept' => (bool) ($field['accept'] ?? false),
                    'default' => $generated || $default === '' ? null : $default,
                    'whenEmpty' => match (true) {
                        $default === '{url}' => 'the service’s own address (https:// and its domain, when it has one)',
                        $generated && ($field['secret'] ?? false) => 'generated, and shown once in deploy_template’s answer',
                        $generated => 'generated',
                        default => null,
                    },
                ];
            }, $template->env),
            'howToDeploy' => $template->isApp()
                ? "deploy_template with template={$template->slug}, the machine, repo (an HTTPS Git repository (public, or a private GitHub one the workspace has connected)) and optionally ref (a branch, tag or commit; the default branch otherwise), name, domain and settings in env."
                : "deploy_template with template={$template->slug}, the machine, and optionally name".($template->portEditable ? ', port' : '').($template->web ? ', domain' : '').' and settings in env.',
        ];
    }

    /**
     * What a deploy of it must be given, beyond a machine.
     *
     * @return list<string>
     */
    private static function needs(ServiceTemplate $template): array
    {
        $needs = $template->isApp() ? ['repo'] : [];
        foreach ($template->env as $field) {
            if ($field['accept'] ?? false) {
                $needs[] = "env.{$field['key']}=true (the person accepts: {$field['label']})";
            } elseif (($field['required'] ?? false) && ($field['default'] ?? '') === '') {
                $needs[] = "env.{$field['key']} ({$field['label']})";
            }
        }

        return $needs;
    }
}
