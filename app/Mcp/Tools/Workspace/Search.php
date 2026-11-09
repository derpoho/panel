<?php

namespace App\Mcp\Tools\Workspace;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workflow;
use App\Support\Deploys\AppReleases;
use App\Support\StatusPages\StatusReport;

/**
 * What ⌘K finds in the dashboard: machines by name or address, services
 * by name or the name shown for them, monitors by name or what they
 * check, and workflows by name. Matched the same way (except that "_"
 * and "%" are only themselves), with a few more facts about each so the
 * agent can act on them.
 */
final class Search extends Tool
{
    public const KINDS = ['machines', 'services', 'monitors', 'workflows'];

    /** As many of each as ⌘K shows. */
    public const DEFAULT_LIMIT = 5;

    public const MAX_LIMIT = 25;

    public function name(): string
    {
        return 'search';
    }

    public function title(): string
    {
        return 'Search the workspace';
    }

    public function description(): string
    {
        return 'Find machines, services, monitors and workflows by text, like ⌘K search in the dashboard. Matching is a case-insensitive “contains”: machines by name or IP address; services by their container or unit name or the name ⌘K shows for them (`shownAs`: a label someone gave it, otherwise a tidied name like “Umami” for serveros-umami-umami-1); monitors by name, URL or host; workflows by name. '
            .'Returns up to `limit` of each kind (default 5) with the ids and keys other tools take (machine id, service key and its machine id, monitor id, workflow id), its status, and its dashboard URL; `more` says which kinds had more matches than shown. Use it to turn a name the person mentions into an id or key for the machine, service, monitor and workflow tools.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'query' => Schema::string('The text to look for, like “postgres”, “shop” or part of an IP address.', maxLength: 100),
            'limit' => Schema::integer('How many of each kind to return (default 5).', 1, self::MAX_LIMIT),
            'kinds' => Schema::array(Schema::string('A kind of thing.', self::KINDS), 'Only look for these kinds (default: all of them).', count(self::KINDS)),
        ], ['query']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(Rules::given($arguments), [
            'query' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'kinds' => ['nullable', 'array', 'max:'.count(self::KINDS)],
            'kinds.*' => ['string', 'in:'.implode(',', self::KINDS)],
        ], ['query.required' => 'Say what to look for: query can’t be blank.']);

        $q = mb_strtolower(trim((string) $data['query']));
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);
        // An empty list is no narrowing, as when it's left out.
        $kinds = ($data['kinds'] ?? []) ?: self::KINDS;
        $workspace = $context->workspace;

        $machines = in_array('machines', $kinds, true) || in_array('services', $kinds, true)
            ? $workspace->machines()->orderBy('name')->get()
            : collect();

        $found = [];
        $more = [];
        $take = function (string $kind, iterable $items, callable $present) use (&$found, &$more, $limit): void {
            $list = [];
            foreach ($items as $item) {
                if (count($list) === $limit) {
                    $more[] = $kind;
                    break;
                }
                $list[] = $present($item);
            }
            $found[$kind] = $list;
        };

        if (in_array('machines', $kinds, true)) {
            $take('machines',
                $machines->filter(fn (Machine $m) => str_contains(mb_strtolower($m->name), $q) || str_contains((string) $m->ip_address, $q)),
                fn (Machine $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'ip' => $m->ip_address,
                    'environment' => $m->environment->value,
                    'status' => Present::connection($m),
                    'url' => $context->url("/machines/{$m->id}"),
                ]);
        }

        if (in_array('services', $kinds, true)) {
            $take('services', $this->services($machines, $q), function (array $match) use ($context) {
                [$machine, $service, $shownAs] = $match;
                $presented = Present::service($machine, $context->describeService($machine, $service), $context);

                return [
                    ...array_intersect_key($presented, array_flip(['key', 'machine', 'name', 'foundAs', 'label', 'app', 'kind', 'status'])),
                    'shownAs' => $shownAs,
                    'url' => $presented['url'],
                ];
            });
        }

        if (in_array('monitors', $kinds, true)) {
            $take('monitors',
                $workspace->monitors()->with('machine')->orderBy('name')->get()
                    ->filter(fn (Monitor $m) => $this->contains($q, $m->name, $m->url, $m->host)),
                fn (Monitor $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'type' => $m->type,
                    'target' => $m->target(),
                    'status' => $m->paused ? 'paused' : $m->status,
                    'muted' => ! $m->paused && $m->muted(),
                    'machineId' => $m->machine_id,
                    'url' => $context->url("/monitors/{$m->id}"),
                ]);
        }

        if (in_array('workflows', $kinds, true)) {
            $take('workflows',
                $workspace->workflows()->orderBy('name')->get(['id', 'name', 'enabled', 'trigger', 'last_run_at'])
                    ->filter(fn (Workflow $w) => $this->contains($q, $w->name)),
                fn (Workflow $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'enabled' => $w->enabled,
                    'trigger' => $w->trigger,
                    'lastRunAt' => $w->last_run_at?->toIso8601String(),
                    'url' => $context->url("/workflows/{$w->id}"),
                ]);
        }

        return [
            'query' => $q,
            ...$found,
            'more' => $more,
            'total' => array_sum(array_map('count', $found)),
        ];
    }

    /**
     * Whether any of the texts contains the query, ignoring case. Done
     * here rather than with LIKE so “_” and “%” are plain characters on
     * every database.
     */
    private function contains(string $q, ?string ...$texts): bool
    {
        foreach ($texts as $text) {
            if ($text !== null && str_contains(mb_strtolower($text), $q)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Services whose name, or the name ⌘K shows for them, contains the
     * text: machine by machine (by name), in the order their scan lists
     * them. Retired app versions are left out, as everywhere.
     *
     * @param  iterable<Machine>  $machines
     * @return \Generator<int, array{0: Machine, 1: array<string, mixed>, 2: string}>
     */
    private function services(iterable $machines, string $q): \Generator
    {
        foreach ($machines as $machine) {
            foreach (AppReleases::visible($machine) as $service) {
                $name = (string) ($service['name'] ?? '');
                if ($name === '' || ! is_string($service['key'] ?? null)) {
                    continue;
                }
                $label = $service['label'] ?? StatusReport::friendlyName($service['app'] ?? $name);
                if (str_contains(mb_strtolower($name), $q) || str_contains(mb_strtolower($label), $q)) {
                    yield [$machine, $service, $label];
                }
            }
        }
    }
}
