<?php

namespace App\Support\Extensions;

use App\Models\User;
use App\Models\Workspace;
use Closure;

/**
 * A section a package adds to the workspace's sidebar (and mobile menu),
 * next to the panel's own in resources/js/components/workspace/workspaceNav.ts.
 */
final readonly class NavItem
{
    /**
     * @param  string  $key  Unique, lowercase: the panel's own are home, machines, updates, services, projects, workflows, fixes, backups, monitors, logs, members, activity and settings.
     * @param  string  $label  In English; it's translated when shared.
     * @param  string  $icon  A name from resources/js/lib/extensionIcons.ts.
     * @param  string  $path  On the workspace's host, like "/billing".
     * @param  string  $group  start, servers, operations, workspace or menu.
     * @param  list<string>  $components  The Inertia pages it's the current section on.
     * @param  string|null  $after  The key of the item it goes after; null for the end of its group.
     * @param  string|null  $before  The key of the item it goes before, for a place that doesn't depend on which items show (it wins over $after when that item is there).
     * @param  (Closure(Workspace, User|null): bool)|null  $visible  Whether to show it; null for always.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $path,
        public string $group,
        public array $components = [],
        public ?string $after = null,
        public ?Closure $visible = null,
        public ?string $before = null,
    ) {}

    public function visibleTo(Workspace $workspace, ?User $user): bool
    {
        return $this->visible === null || ($this->visible)($workspace, $user) === true;
    }

    /**
     * @return array{key: string, label: string, icon: string, path: string, group: string, components: list<string>, after: string|null, before: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => __($this->label),
            'icon' => $this->icon,
            'path' => $this->path,
            'group' => $this->group,
            'components' => $this->components,
            'after' => $this->after,
            'before' => $this->before,
        ];
    }
}
