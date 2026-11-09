<?php

namespace App\Mcp\Tools\StatusPage;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\StatusPage;
use App\Models\WorkspaceStatusPage;
use App\Support\StatusPages\FleetStatusReport;
use App\Support\StatusPages\StatusReport;

/**
 * What a status page shows right now, built the way /status/{slug} builds
 * it, and who can see it.
 */
final class PreviewStatusPage extends Tool
{
    public function name(): string
    {
        return 'preview_status_page';
    }

    public function title(): string
    {
        return 'Preview a status page';
    }

    public function description(): string
    {
        return 'Shows what a status page shows visitors right now, built exactly as /status/{slug} builds it: the title, description and notice, the headline (`overall`: operational, degraded, down or unknown, with its words, like “Partial outage”), whether the machine is reporting, the average uptime, '
            .'each section in order with its worst status and whether it starts folded (long pages fold sections that are all operational), each component’s name, status, uptime and response time, and the last two weeks’ incidents. `badge` is what the README badge says. '
            .'`visitors` says who can see it: anyone, members only, or nobody yet (switched off, on a plan without status pages, or never made: then this is what it would show). '
            .'Give `machine` for a machine’s page, or `fleet` true for the workspace’s fleet page. `include_days` adds the 90 days of uptime bars per component (long).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('The machine whose page to preview: its id or name. Leave out with fleet.'),
            'fleet' => Schema::boolean('Preview the workspace’s fleet page instead of a machine’s.'),
            'include_days' => Schema::boolean('Add the day-by-day uptime (90 days) for each component.'),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['sometimes', StatusPages::reference()],
            'fleet' => ['sometimes', 'boolean'],
            'include_days' => ['sometimes', 'boolean'],
        ]);
        $fleet = (bool) ($data['fleet'] ?? false);
        if ($fleet === isset($data['machine'])) {
            throw new ToolError('Give either machine (for its status page) or fleet: true (for the workspace’s fleet page).');
        }

        if ($fleet) {
            $page = StatusPages::fleetPage($context);
            $report = (new FleetStatusReport($page))->toArray();
            $about = ['kind' => 'fleet', 'machine' => null];
        } else {
            $machine = $context->machine($data['machine']);
            $page = StatusPages::machinePage($context, $machine);
            $report = (new StatusReport($page))->toArray();
            $about = ['kind' => 'machine', 'machine' => ['id' => $machine->id, 'name' => $machine->name]];
        }
        $live = $page->exists && $page->live();

        return [
            'page' => [
                ...$about,
                'exists' => $page->exists,
                'enabled' => (bool) $page->enabled,
                'live' => $live,
                'public' => (bool) $page->public,
                'slug' => $page->slug,
                ...StatusPages::addresses($page->slug),
            ],
            'visitors' => $this->visitors($context, $page),
            'badge' => $live && $page->public ? $this->badge($report['overall']) : null,
            'report' => StatusPages::preview($report, (bool) ($data['include_days'] ?? false)),
        ];
    }

    /** Who sees the page now, in words. */
    private function visitors(McpContext $context, StatusPage|WorkspaceStatusPage $page): string
    {
        $fleet = $page instanceof WorkspaceStatusPage;

        return match (true) {
            ! $page->exists => 'Nobody: there’s no page yet (this is what one would show, from the default settings).',
            ! $page->enabled => 'Nobody: the page is switched off, so its address answers “not found”.',
            ! $page->live() => 'Nobody: '.($fleet ? 'the fleet page needs Business' : 'status pages need Pro or Business').', so its address answers “not found”.',
            $page->public => 'Anyone with the link.',
            default => "Only members of {$context->workspace->name}, signed in; anyone else gets “not found” (or the sign-in page).",
        };
    }

    /**
     * What the badge says, as PublicStatusController draws it.
     *
     * @param  array{status: string, label: string}  $overall
     */
    private function badge(array $overall): string
    {
        return match ($overall['status']) {
            StatusReport::OPERATIONAL => 'operational',
            StatusReport::DEGRADED => 'degraded',
            StatusReport::DOWN => $overall['label'] === 'Major outage' ? 'major outage' : 'partial outage',
            default => 'unknown',
        };
    }
}
