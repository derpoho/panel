<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

/**
 * Delete a workflow and its runs, as its editor's Delete does.
 */
final class DeleteWorkflow extends Tool
{
    public function name(): string
    {
        return 'delete_workflow';
    }

    public function title(): string
    {
        return 'Delete a workflow';
    }

    public function description(): string
    {
        return 'Deletes a workflow and its run history, like Delete in its editor; a webhook URL it had stops working. To keep it and its runs, turn it off instead (update_workflow enabled false). Can’t be undone: ask the person first, then pass confirm set to the workflow’s name exactly.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'workflow' => Workflows::workflowSchema(),
            'confirm' => Schema::confirm('the workflow’s name'),
        ], ['workflow', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'workflow' => ['required', Workflows::reference()],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $workflow = Workflows::find($context, $data['workflow']);
        Workflows::confirm($data, $workflow, 'deletes it with its runs');

        $deleted = ['id' => $workflow->id, 'name' => $workflow->name];
        $context->record('workflow.remove', $workflow->name);
        $workflow->delete();

        return [
            'deleted' => $deleted,
            'note' => "Deleted “{$deleted['name']}” and its runs.",
        ];
    }
}
