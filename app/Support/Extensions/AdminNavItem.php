<?php

namespace App\Support\Extensions;

use App\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * A page in the staff admin's sidebar (resources/js/layouts/AdminLayout.vue).
 * The panel adds the admin pages it serves itself and each package adds its
 * own, with Extensions::addAdminNavItem. They're shared with admin pages as
 * the adminNav prop, grouped and in their places, only the ones this person
 * may open. An install with none has no admin sidebar to show.
 */
final readonly class AdminNavItem
{
    /**
     * The sidebar's groups, in order: home is what staff open every day
     * (no heading); tools is the row of icons at the bottom, outside
     * services that open in a new tab.
     */
    public const array GROUPS = ['home', 'customers', 'growth', 'content', 'platform', 'tools'];

    /**
     * @param  string  $key  Unique, lowercase, like "tickets" or "docs".
     * @param  string  $label  As the sidebar shows it (the admin is in English).
     * @param  string  $icon  A name from resources/js/lib/adminIcons.ts.
     * @param  string  $path  Where it goes, like "/admin/tickets", or a full URL for a tool.
     * @param  string  $group  One of GROUPS.
     * @param  list<string>  $components  The Inertia pages it's the current page on; one ending in * matches every page starting with the rest, like "admin/tickets/*".
     * @param  string|null  $after  The key of the item it goes after; null for the end of its group.
     * @param  string|null  $before  The key of the item it goes before, which wins over $after when that item shows.
     * @param  (Closure(User): bool)|null  $visible  Who sees it; null for everyone who can open the admin (viewAdmin).
     * @param  string|null  $badge  The page prop with a count to show next to it, like "ticketsUnread" (shared by the package, so a partial reload of it updates the badge).
     * @param  bool  $external  Not an Inertia page: a full page load, like Laravel Pulse.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $path,
        public string $group,
        public array $components = [],
        public ?string $after = null,
        public ?string $before = null,
        public ?Closure $visible = null,
        public ?string $badge = null,
        public bool $external = false,
    ) {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException("There's no admin sidebar group called {$group}.");
        }
    }

    public function visibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->visible === null ? $user->can('viewAdmin') : ($this->visible)($user) === true;
    }

    /**
     * Items of one group in their places: those without a place in the
     * order they were added, then each of the rest before or after the
     * item it names, whichever order they were added in. One whose item
     * isn't there goes at the end.
     *
     * @param  list<self>  $items
     * @return list<self>
     */
    public static function arrange(array $items): array
    {
        $keys = array_map(fn (self $item) => $item->key, $items);
        $anchor = fn (self $item): ?array => match (true) {
            $item->before !== null && in_array($item->before, $keys, true) => ['before', $item->before],
            $item->after !== null && in_array($item->after, $keys, true) => ['after', $item->after],
            default => null,
        };

        $placed = array_values(array_filter($items, fn (self $item) => $anchor($item) === null));
        $waiting = array_values(array_filter($items, fn (self $item) => $anchor($item) !== null));

        do {
            $progress = false;
            foreach ($waiting as $i => $item) {
                [$side, $key] = $anchor($item);
                $at = array_search($key, array_map(fn (self $p) => $p->key, $placed), true);
                if ($at === false) {
                    continue;
                }
                array_splice($placed, $side === 'before' ? $at : $at + 1, 0, [$item]);
                unset($waiting[$i]);
                $progress = true;
            }
        } while ($progress && $waiting !== []);

        return [...$placed, ...array_values($waiting)];
    }

    /**
     * @return array{key: string, label: string, icon: string, href: string, components: list<string>, badge: string|null, external: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'href' => $this->path,
            'components' => $this->components,
            'badge' => $this->badge,
            'external' => $this->external,
        ];
    }
}
