<?php

namespace App\Support\ServiceTemplates;

use App\Models\Machine;

/**
 * Every service template, loaded once per request from
 * resources/service-templates and ordered by category, then name.
 */
class ServiceTemplateCatalog
{
    /** @var array<string, ServiceTemplate>|null */
    private ?array $templates = null;

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * @return array<string, ServiceTemplate>
     */
    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $templates = [];
        foreach (glob(($this->directory ?? resource_path('service-templates')).'/*.php') ?: [] as $path) {
            $slug = basename($path, '.php');
            $templates[$slug] = ServiceTemplate::fromArray($slug, require $path);
        }

        uasort($templates, fn (ServiceTemplate $a, ServiceTemplate $b) => [
            array_search($a->category, ServiceTemplate::CATEGORIES, true), $a->name,
        ] <=> [
            array_search($b->category, ServiceTemplate::CATEGORIES, true), $b->name,
        ]);

        return $this->templates = $templates;
    }

    /**
     * The catalogue as the marketing pages show it: no ports, env or files.
     *
     * @return list<array{slug: string, name: string, icon: string, category: string, description: string, fromRepo: bool}>
     */
    public function forMarketing(): array
    {
        return array_values(array_map(fn (ServiceTemplate $template) => [
            'slug' => $template->slug,
            'name' => $template->name,
            'icon' => $template->icon,
            'category' => $template->category,
            'description' => $template->description,
            'fromRepo' => $template->kind === ServiceTemplate::KIND_APP,
        ], $this->all()));
    }

    /**
     * The best known apps, first in line when a search ties: what the
     * marketing pages show on their walls of icons.
     */
    public const FEATURED = [
        'postgres', 'redis', 'mysql', 'mongodb', 'n8n', 'grafana', 'nextcloud',
        'uptime-kuma', 'ghost', 'wordpress', 'plausible', 'umami', 'mattermost',
        'gitea', 'minio', 'vaultwarden', 'ollama', 'home-assistant', 'meilisearch',
        'metabase', 'keycloak', 'nocodb', 'appsmith', 'directus', 'strapi',
        'mariadb', 'rabbitmq', 'elasticsearch', 'jellyfin', 'immich', 'listmonk',
        'pocketbase', 'authentik', 'portainer', 'valkey', 'clickhouse',
        'excalidraw', 'ntfy', 'matomo',
    ];

    /**
     * The marketing catalogue matched against what someone typed: an exact
     * name first, then names that start with it, then names that contain it,
     * then descriptions that mention it. Ties go to the featured apps, then
     * the shorter name.
     *
     * @return list<array{slug: string, name: string, icon: string, category: string, description: string, fromRepo: bool}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $matches = [];
        foreach ($this->forMarketing() as $template) {
            $name = mb_strtolower($template['name']);
            $slug = $template['slug'];
            $rank = match (true) {
                $name === $query || $slug === $query => 0,
                str_starts_with($name, $query) || str_starts_with($slug, $query) => 1,
                str_contains($name, $query) || str_contains($slug, $query) => 2,
                str_contains(mb_strtolower($template['description']), $query) => 3,
                default => null,
            };
            if ($rank === null) {
                continue;
            }
            $featured = array_search($slug, self::FEATURED, true);
            $matches[] = [[$rank, $featured === false ? PHP_INT_MAX : $featured, mb_strlen($name), $name], $template];
        }

        usort($matches, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_slice(array_column($matches, 1), 0, $limit);
    }

    public function find(string $slug): ?ServiceTemplate
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toPageProps(?Machine $machine = null): array
    {
        return array_values(array_map(fn (ServiceTemplate $t) => $t->toPageProps($machine), $this->all()));
    }
}
