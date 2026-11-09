<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use Closure;

/**
 * A page a package adds to the workspace's settings menu, next to the
 * panel's own in resources/js/layouts/workspace/WorkspaceSettingsLayout.vue.
 */
final readonly class SettingsItem
{
    /**
     * @param  string  $key  Unique, lowercase, like "sso".
     * @param  string  $label  In English; it's translated when shared.
     * @param  string  $icon  A name from resources/js/lib/extensionIcons.ts.
     * @param  string  $path  On the workspace's host, like "/settings/sso".
     * @param  string  $group  workspace, machines, developers or account.
     * @param  string  $component  The Inertia page it opens, like "workspace/settings/Sso".
     * @param  string  $keywords  Other words people search settings by.
     * @param  list<array{label: string, anchor: string, keywords?: string}>  $sections  Parts of the page the settings search finds.
     * @param  (Closure(Workspace, User|null): bool)|null  $visible  Whether to show it; null for always.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $path,
        public string $group,
        public string $component,
        public string $keywords = '',
        public array $sections = [],
        public ?Closure $visible = null,
    ) {}

    public function visibleTo(Workspace $workspace, ?User $user): bool
    {
        return $this->visible === null || ($this->visible)($workspace, $user) === true;
    }

    /**
     * @return array{key: string, label: string, icon: string, path: string, group: string, component: string, keywords: string, sections: list<array{label: string, anchor: string, keywords: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => __($this->label),
            'icon' => $this->icon,
            'path' => $this->path,
            'group' => $this->group,
            'component' => $this->component,
            'keywords' => $this->keywords,
            'sections' => array_map(fn (array $section) => [
                'label' => __($section['label']),
                'anchor' => $section['anchor'],
                'keywords' => $section['keywords'] ?? '',
            ], $this->sections),
        ];
    }
}
