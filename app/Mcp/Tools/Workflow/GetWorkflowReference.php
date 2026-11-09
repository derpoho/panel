<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Workflows\WorkflowInputs;

/**
 * Everything needed to draw a workflow: triggers, steps, ports, rules,
 * conditions, placeholders and limits, with what this workspace's steps
 * can choose from.
 */
final class GetWorkflowReference extends Tool
{
    public function name(): string
    {
        return 'get_workflow_reference';
    }

    public function title(): string
    {
        return 'How workflows are built';
    }

    public function description(): string
    {
        return 'Read this before writing a graph for update_workflow. Returns the graph format (nodes, edges, ports, how a run follows them), every trigger event with its filter rules and the data it carries, every step kind with its ports and rules (type, required, allowed values, defaults, and where “@trigger” or “@all” may be used), the If step’s conditions, the {{placeholders}} alerts, requests and commands can use, the limits, and a small complete example graph. '
            .'`choices` (unless include_choices is false) lists what this workspace’s steps can point at, as the editor’s menus do: machines (id, name, services with key, name, managed and container, and the apps deployed there by name) monitors (id, name), and projects (id, name, environments with id, name and source: the environment each was copied from).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'include_choices' => Schema::boolean('Include this workspace’s machines, services, deployed apps and monitors (default true).'),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['include_choices' => ['sometimes', 'boolean']]);

        return [
            ...WorkflowReference::all(),
            'choices' => ($data['include_choices'] ?? true) ? WorkflowInputs::options($context->workspace) : null,
        ];
    }
}
