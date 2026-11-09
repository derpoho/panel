<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;

/**
 * One workflow as its editor opens it: the graph, the steps in words,
 * what stops it being turned on, and its latest runs.
 */
final class GetWorkflow extends Tool
{
    public function name(): string
    {
        return 'get_workflow';
    }

    public function title(): string
    {
        return 'Get a workflow';
    }

    public function description(): string
    {
        return 'Returns one workflow as its editor shows it: name, description, whether it’s on, its trigger (event, label, in words), who its machine steps run as (runAs: whoever saved it last), next and last run, '
            .'`graph` (nodes with id, kind, title, description, rules and position; edges with source, port and target: the exact shape update_workflow takes back), '
            .'`steps` in the order a run reaches them, each in plain words (“Restart shop on web-1”) with where each port leads next and its problem if any (steps nothing leads to are marked connected: false), '
            .'`problems` (what must be fixed before it can be turned on or run: the same warnings the editor marks), `hints` (things that would make a run fail but don’t block it, like a service that isn’t managed), `canTurnOn`, and its 10 latest runs. '
            .'For a webhook trigger it says whether the URL exists, but never the URL itself: it’s a secret the person copies from the dashboard. get_workflow_reference explains kinds, ports and rules.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'workflow' => Workflows::workflowSchema(),
        ], ['workflow']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['workflow' => ['required', Workflows::reference()]]);

        return WorkflowView::full(Workflows::find($context, $data['workflow']), $context);
    }
}
