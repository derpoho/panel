<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Workflows\WorkflowSchedule;
use App\Support\Workflows\WorkflowSteps;

/**
 * Save a workflow as the editor's Save and its on/off switch do: name,
 * description, the graph, and whether it's on, with the same checks.
 */
final class UpdateWorkflow extends Tool
{
    public function name(): string
    {
        return 'update_workflow';
    }

    public function title(): string
    {
        return 'Change a workflow, or turn it on or off';
    }

    public function description(): string
    {
        return 'Saves a workflow as the dashboard’s editor does. Give any of: name, description (empty clears it), enabled (true turns it on, so it runs on its own when its trigger fires; false turns it off), and graph, which REPLACES the whole graph: send every node and edge to keep (start from get_workflow’s graph; get_workflow_reference describes kinds, ports and rules). What you leave out stays as it is. '
            .'Steps that still need something (a machine, a service, a monitor…) can be saved while it’s off: they come back in `problems`. It can only be turned on, or stay on, with no problems, and on the Pro or Business plan. '
            .'Saving makes you the one its machine steps run as (with your permissions), and moves a schedule’s next run; a webhook trigger gets its secret URL (shown only in the dashboard). '
            .'A workflow with a command step needs two-factor authentication on, and a connection allowed to run commands. Turning one on means it will act on machines by itself (restart, stop, deploy, run commands): say what it will do and check with the person first. Returns the saved workflow as get_workflow does, and what changed.';
    }

    public function inputSchema(): array
    {
        $object = fn (array $properties, array $required, string $description) => [
            'type' => 'object',
            'description' => $description,
            'properties' => $properties,
            'required' => $required,
        ];

        return Schema::object([
            'workflow' => Workflows::workflowSchema(),
            'name' => Schema::string('A new name (up to 80 characters).', maxLength: 80),
            'description' => Schema::string('A new description (up to 280 characters); empty to clear it.', maxLength: 280),
            'enabled' => Schema::boolean('true to turn it on (it must have no problems), false to turn it off.'),
            'graph' => $object([
                'nodes' => Schema::array($object([
                    'id' => Schema::string('Your id for the step: letters, digits, “-” and “_”.', maxLength: 64),
                    'kind' => Schema::string('What the step does.', array_keys(WorkflowSteps::PORTS)),
                    'title' => Schema::string('Its title on the canvas (default: the kind’s).', maxLength: 80),
                    'description' => Schema::string('A line under the title (default: the kind’s).', maxLength: 280),
                    'rules' => ['type' => 'object', 'description' => 'Its settings, by name: strings, numbers, booleans or null (see get_workflow_reference).'],
                    'x' => ['type' => 'number', 'description' => 'Canvas position (optional; laid out if left out).'],
                    'y' => ['type' => 'number', 'description' => 'Canvas position (optional).'],
                ], ['id', 'kind'], 'A step.'), 'Every step, the trigger included.', WorkflowSteps::MAX_NODES),
                'edges' => Schema::array($object([
                    'source' => Schema::string('The id of the step it leaves.', maxLength: 64),
                    'port' => Schema::string('Which way out of the source (default out).', ['out', 'failed', 'true', 'false']),
                    'target' => Schema::string('The id of the step it leads to.', maxLength: 64),
                    'id' => Schema::string('Optional id.', maxLength: 64),
                ], ['source', 'target'], 'A connection.'), 'Every connection between steps.', WorkflowSteps::MAX_EDGES),
            ], ['nodes'], 'The whole graph, replacing what’s there.'),
        ], ['workflow']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'workflow' => ['required', Workflows::reference()],
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:280'],
            'enabled' => ['sometimes', 'boolean'],
            'graph' => ['sometimes', 'array'],
        ]);
        if (! array_key_exists('name', $data) && ! array_key_exists('description', $data) && ! array_key_exists('enabled', $data) && ! array_key_exists('graph', $data)) {
            throw new ToolError('Give what to change: name, description, enabled or graph.');
        }

        $workspace = $context->workspace;
        $workflow = Workflows::find($context, $data['workflow']);
        $graph = WorkflowSteps::clean(array_key_exists('graph', $data) ? GraphInput::prepare($arguments['graph']) : $workflow->graph);
        $problems = WorkflowSteps::problems($graph, $workspace);
        $enabled = (bool) ($data['enabled'] ?? $workflow->enabled);

        if ($enabled && ! $workspace->hasAutomations()) {
            throw new ToolError('Workflows come with Pro. Choose Pro or Business to turn one on.'.($workflow->enabled ? ' To save it, turn it off too (enabled false).' : ''));
        }
        if ($enabled && $problems !== []) {
            throw new ToolError('Fix the steps marked with a warning before turning it on. '.Workflows::problemText(Workflows::problems($problems, $graph))
                .($workflow->enabled && ! array_key_exists('enabled', $data) ? ' It’s on, so it stays on only with no problems: to save it as a draft, pass enabled false.' : ''));
        }
        Workflows::requireCommands($context, $graph, 'saving');
        if (WorkflowSteps::hasCommand($graph) && ! $context->user->hasEnabledTwoFactorAuthentication()) {
            throw new ToolError('Workflows that run commands run them as you, so turn on two-factor authentication first.');
        }

        $rules = (array) (collect($graph['nodes'])->firstWhere('kind', 'trigger')['rules'] ?? []);
        $event = (string) ($rules['event'] ?? 'manual');
        $wasEnabled = $workflow->enabled;
        $before = [
            'name' => $workflow->name,
            'description' => $workflow->description,
            'enabled' => $workflow->enabled,
            'graph' => json_encode(WorkflowSteps::clean($workflow->graph)),
        ];

        $workflow->fill([
            'name' => array_key_exists('name', $data) ? trim($data['name']) : $workflow->name,
            'description' => array_key_exists('description', $data)
                ? (filled($data['description']) ? trim($data['description']) : null)
                : $workflow->description,
            'enabled' => $enabled,
            'graph' => $graph,
            'trigger' => $event,
            'run_as' => $context->user->id,
            'next_run_at' => $event === 'schedule' ? WorkflowSchedule::next($rules) : null,
        ]);
        if ($event === 'webhook' && $workflow->webhook_token === null) {
            $workflow->rotateWebhook();
        }
        $workflow->save();

        $context->record(match (true) {
            $enabled && ! $wasEnabled => 'workflow.enable',
            ! $enabled && $wasEnabled => 'workflow.disable',
            default => 'workflow.update',
        }, $workflow->name);

        $changed = array_keys(array_filter([
            'name' => $before['name'] !== $workflow->name,
            'description' => $before['description'] !== $workflow->description,
            'enabled' => $before['enabled'] !== $workflow->enabled,
            'graph' => $before['graph'] !== json_encode($graph),
        ]));
        $view = WorkflowView::full($workflow->refresh(), $context, runs: 0);

        return [
            'changed' => $changed,
            'workflow' => $view,
            'note' => match (true) {
                $enabled && ! $wasEnabled => "“{$workflow->name}” is on: it runs when its trigger fires ({$view['trigger']['words']}).",
                ! $enabled && $wasEnabled => "“{$workflow->name}” is off: it won’t run on its own.",
                $view['problems'] !== [] => "Saved “{$workflow->name}”, still off: fix its problems before turning it on.",
                default => "Saved “{$workflow->name}”.".($enabled ? '' : ' It’s off; turn it on with enabled true.'),
            },
        ];
    }
}
