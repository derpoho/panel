<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Workflows\WorkflowTemplates;
use Illuminate\Validation\Rule;

/**
 * A new workflow from a template, as the dashboard's "New workflow"
 * picker makes one: off, run as the person, filled in where the
 * workspace allows.
 */
final class CreateWorkflow extends Tool
{
    public function name(): string
    {
        return 'create_workflow';
    }

    public function title(): string
    {
        return 'Create a workflow';
    }

    public function description(): string
    {
        return 'Creates a workflow from a template (list_workflow_templates; “blank” is just a trigger you run by hand), as the dashboard’s “New workflow” does. It starts off (enabled false): nothing runs until it’s turned on. '
            .'Templates fill in the workspace’s first machine, managed service, deployed app and monitor; check the returned `problems` and `hints`, fix them (and adapt the steps) with update_workflow, then turn it on with update_workflow enabled true. '
            .'name replaces the template’s name. Needs the Pro or Business plan; a workspace can have '.Workflows::LIMIT.' workflows. Returns the workflow as get_workflow does.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'template' => Schema::string('The template’s key.', array_column(WorkflowTemplates::list(), 'key')),
            'name' => Schema::string('A name for it (up to 80 characters). Default: the template’s name.', maxLength: 80),
        ], ['template']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'template' => ['required', 'string', Rule::in(array_column(WorkflowTemplates::list(), 'key'))],
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], ['template.in' => 'The template must be one of: '.implode(', ', array_column(WorkflowTemplates::list(), 'key')).'.']);

        $workspace = $context->workspace;
        if (! $workspace->hasAutomations()) {
            throw new ToolError('Workflows come with Pro. Choose Pro or Business to use them.');
        }
        if ($workspace->workflows()->count() >= Workflows::LIMIT) {
            throw new ToolError('A workspace can have '.Workflows::LIMIT.' workflows.');
        }

        $template = WorkflowTemplates::build($data['template'], $workspace);
        $workflow = $workspace->workflows()->create([
            'name' => filled($data['name'] ?? null) ? trim($data['name']) : $template['name'],
            'graph' => $template['graph'],
            'trigger' => (string) (((array) (collect($template['graph']['nodes'])->firstWhere('kind', 'trigger')['rules'] ?? []))['event'] ?? 'manual'),
            'run_as' => $context->user->id,
            'enabled' => false,
        ]);
        if ($workflow->trigger === 'webhook') {
            $workflow->rotateWebhook();
            $workflow->save();
        }
        $context->record('workflow.add', $workflow->name);

        $view = WorkflowView::full($workflow->refresh(), $context, runs: 0);

        return [
            'workflow' => $view,
            'note' => "Created “{$workflow->name}”. It’s off."
                .($view['problems'] === [] ? ' Its steps are filled in: review them, then turn it on with update_workflow (enabled true), or try it with run_workflow.' : ' Fix its problems with update_workflow before turning it on.'),
        ];
    }
}
