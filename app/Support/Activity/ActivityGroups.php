<?php

namespace App\Support\Activity;

use Illuminate\Database\Eloquent\Builder;

/**
 * Actions as people look for them on the Activity page: "Backups", not
 * backup.run, backups.schedule and backup_destination.add. A pattern
 * ending in a dot is a prefix. A group whose prefix would also take
 * another group's actions names them as its exceptions: a deploy is a
 * service.* action, but it belongs with Deploys.
 */
final class ActivityGroups
{
    /** @var array<string, array{0: string, 1: list<string>, 2?: list<string>}> label, patterns, exceptions */
    public const GROUPS = [
        'deploys' => ['Deploys and apps', ['service.deploy', 'service.rollback', 'app.', 'github.', 'project.']],
        'services' => ['Services', ['service.', 'service_group.'], ['service.deploy', 'service.rollback']],
        'machines' => ['Machines and console', ['machine.', 'machines.', 'cloud.', 'machine_group.', 'machine_policy.', 'updates.', 'daemon.', 'console.', 'file.', 'job.']],
        'monitors' => ['Monitors and alerts', ['monitor.', 'alerts.', 'issues.', 'status_page.']],
        'backups' => ['Backups', ['backup.', 'backups.', 'backup_destination.']],
        'workflows' => ['Workflows and fixes', ['workflow.', 'fix.']],
        'members' => ['Members and roles', ['member.', 'role.']],
        'workspace' => ['Workspace and billing', ['workspace.', 'plan.', 'domain.']],
        'security' => ['Security, MCP and API', ['security.', 'sign_in.', 'sso.', 'email_domain.', 'mcp.', 'ai.', 'api.', 'scim.', 'activity.']],
    ];

    /** The group an action is in, or null for one nobody put anywhere. */
    public static function of(string $action): ?string
    {
        foreach (self::GROUPS as $key => $group) {
            if (in_array($action, $group[2] ?? [], true)) {
                continue;
            }
            foreach ($group[1] as $pattern) {
                if (self::matches($action, $pattern)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The groups for the filter menu, labels translated.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => [$label]) {
            $out[] = ['value' => $key, 'label' => is_string($word = __($label)) ? $word : $label];
        }

        return $out;
    }

    /**
     * Only entries in this group: matching one of its patterns, and not
     * one of its exceptions.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function scope(Builder $query, string $group): void
    {
        $query->where(fn (Builder $q) => self::any($q, self::GROUPS[$group][1]));
        if (($except = self::GROUPS[$group][2] ?? []) !== []) {
            $query->whereNotIn('action', $except);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $patterns
     */
    private static function any(Builder $query, array $patterns): void
    {
        foreach ($patterns as $pattern) {
            // A plain prefix, not LIKE: action names have underscores in them.
            str_ends_with($pattern, '.')
                ? $query->orWhereRaw('substr(action, 1, ?) = ?', [strlen($pattern), $pattern])
                : $query->orWhere('action', $pattern);
        }
    }

    private static function matches(string $action, string $pattern): bool
    {
        return str_ends_with($pattern, '.') ? str_starts_with($action, $pattern) : $action === $pattern;
    }
}
