<?php

namespace App\Mcp\Tools\Workspace;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Health\WorkspaceHealth;

/**
 * "Needs attention" from the workspace Home: servers that stopped
 * reporting or are filling up, services that stopped or keep crashing,
 * sites that are down or whose certificates are running out, and backups
 * that failed, are late or were never set up. Worst first.
 */
final class ListIssues extends Tool
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    public function __construct(private readonly Issues $issues) {}

    public function name(): string
    {
        return 'list_issues';
    }

    public function title(): string
    {
        return 'List what needs attention';
    }

    public function description(): string
    {
        return 'What the workspace Home lists under “Needs attention”, worst first: machines that stopped reporting, never connected, or are low on disk or memory; services that stopped unexpectedly or keep restarting; monitors that are down or whose TLS certificate expires soon; and backups that failed, are overdue or aren’t set up for production machines. Items someone marked as read stay hidden until they happen again. '
            .'Each item has its id (for dismiss_issues), severity (critical, warning, or notice: expected or less urgent, such as during maintenance or on a muted monitor), kind (machine.offline, machine.never_connected, machine.disk, machine.memory, machine.maintenance, service.down, service.crash_loop, service.needs_repair, monitor.down, monitor.certificate, backup.failed, backup.overdue or backup.off), title and plain-language detail as the dashboard says them, a short `fix` with the next step, when it started (`since`, ISO 8601, when known), the machine, service (key) and monitor (id) it’s about, and the dashboard link to fix it. '
            .'`needsAttention` counts the critical and warning items, as the Home’s badge does, and `counts` gives each severity: both are for the whole workspace whatever the filters; `matching` is how many the filters let through. Use it to answer “is anything wrong?” and to decide what to look at first.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'severity' => Schema::string('Only items at least this serious: critical, warning (critical and warning), or notice (everything, the default).', Issues::SEVERITIES),
            'machine' => Schema::machine('Only items about this machine (its id or name), including its monitors.'),
            'limit' => Schema::integer('Most items to return (default 50).', 1, self::MAX_LIMIT),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(Rules::given($arguments), [
            'severity' => ['nullable', 'string', 'in:'.implode(',', Issues::SEVERITIES)],
            'machine' => ['nullable', 'max:120', Rules::reference(...)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $machine = ($data['machine'] ?? null) !== null ? $context->machine($data['machine']) : null;
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        $all = $this->issues->current($context);
        $counts = array_count_values(array_column($all, 'severity'));

        $shown = $all;
        if (isset($data['severity'])) {
            $worst = array_slice(Issues::SEVERITIES, 0, array_search($data['severity'], Issues::SEVERITIES, true) + 1);
            $shown = array_values(array_filter($shown, fn (array $issue) => in_array($issue['severity'], $worst, true)));
        }

        if ($machine !== null) {
            // A monitor's issues are about the machine it watches, if any.
            $presented = array_values(array_filter(
                $this->issues->present($context, $shown),
                fn (array $issue) => ($issue['machine']['id'] ?? null) === $machine->id,
            ));
            $matching = count($presented);
            $presented = array_slice($presented, 0, $limit);
        } else {
            $matching = count($shown);
            $presented = $this->issues->present($context, array_slice($shown, 0, $limit));
        }

        return [
            'needsAttention' => ($counts[WorkspaceHealth::CRITICAL] ?? 0) + ($counts[WorkspaceHealth::WARNING] ?? 0),
            'counts' => [
                'critical' => $counts[WorkspaceHealth::CRITICAL] ?? 0,
                'warning' => $counts[WorkspaceHealth::WARNING] ?? 0,
                'notice' => $counts[WorkspaceHealth::NOTICE] ?? 0,
            ],
            'matching' => $matching,
            'issues' => $presented,
            'truncated' => $matching > count($presented),
            'url' => $context->url('/'),
        ];
    }
}
