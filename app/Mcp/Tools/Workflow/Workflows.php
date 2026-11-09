<?php

namespace App\Mcp\Tools\Workflow;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\ToolError;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Support\Workflows\WorkflowSteps;
use Closure;

/**
 * What the workflow tools share: finding a workflow or a run in this
 * workspace only, the checks the dashboard makes before changing or
 * running one, and the argument schema they repeat.
 */
final class Workflows
{
    /** The most workflows a workspace may have, as the dashboard allows. */
    public const LIMIT = 100;

    /**
     * A workflow in this workspace, by id or name (any case).
     */
    public static function find(McpContext $context, mixed $reference): Workflow
    {
        $reference = trim((string) (is_scalar($reference) ? $reference : ''));
        if ($reference === '') {
            throw new ToolError('Say which workflow: its id or name. list_workflows shows them.');
        }

        $query = $context->workspace->workflows();
        if (ctype_digit($reference) && ($workflow = (clone $query)->find((int) $reference)) !== null) {
            return $workflow;
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several workflows are called “{$reference}”: ".$matches->map(fn (Workflow $w) => "#{$w->id}")->implode(', ').'. Use the id.');
        }

        $close = (clone $query)->whereRaw('lower(name) like ?', ['%'.mb_strtolower($reference).'%'])->orderBy('name')->limit(5)->pluck('name');

        throw new ToolError("No workflow “{$reference}” in {$context->workspace->name}.".($close->isNotEmpty() ? ' Did you mean: '.$close->implode(', ').'?' : ' list_workflows shows them.'));
    }

    /**
     * A run of one of this workspace's workflows, by id; of the given
     * workflow when there is one.
     */
    public static function run(McpContext $context, mixed $id, ?Workflow $workflow = null): WorkflowRun
    {
        $id = is_int($id) || (is_string($id) && ctype_digit(ltrim($id, '#'))) ? (int) ltrim((string) $id, '#') : 0;
        $run = $id > 0
            ? WorkflowRun::query()->whereIn('workflow_id', $context->workspace->workflows()->select('id'))->find($id)
            : null;
        if ($run === null) {
            throw new ToolError('No run #'.($id ?: '?').' of a workflow in '.$context->workspace->name.'. list_workflow_runs shows them (runs are kept '.WorkflowRun::KEEP_DAYS.' days).');
        }
        if ($workflow !== null && $run->workflow_id !== $workflow->id) {
            throw new ToolError("Run #{$run->id} isn’t a run of “{$workflow->name}”.");
        }

        return $run;
    }

    /**
     * The dashboard's "type the name to confirm".
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function confirm(array $arguments, Workflow $workflow, string $doing): void
    {
        $given = $arguments['confirm'] ?? null;
        if (! is_string($given) || trim($given) === '') {
            throw new ToolError("Check with the person first: this {$doing} and can’t be undone. Then call again with confirm set to the workflow’s name exactly: “{$workflow->name}”.");
        }
        if (trim($given) !== $workflow->name) {
            throw new ToolError("confirm doesn’t match. It must be the workflow’s name exactly: “{$workflow->name}”. Nothing was changed.");
        }
    }

    /**
     * A workflow with a command step runs shell commands in containers,
     * so through MCP saving or running one is for connections allowed to
     * run commands, like the console.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    public static function requireCommands(McpContext $context, array $graph, string $doing): void
    {
        if (WorkflowSteps::hasCommand($graph) && ! $context->allows(Scopes::COMMANDS)) {
            if ($context->via === 'assistant') {
                throw new ToolError("This workflow runs commands inside containers, and Ask AI never saves or runs commands. Tell the person to {$doing} it in the dashboard's workflow editor.");
            }
            throw new ToolError("This workflow runs commands inside containers, so {$doing} it needs a connection allowed to run commands. Reconnect and allow “Run commands” (it needs two-factor authentication), or do it in the dashboard.");
        }
    }

    /**
     * The problems that stop it being turned on, as a list: the step's id
     * (null for the whole workflow), its title, and what to fix.
     *
     * @param  array<string, string>  $problems  from WorkflowSteps::problems()
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{step: string|null, title: string|null, problem: string}>
     */
    public static function problems(array $problems, array $graph): array
    {
        $titles = collect($graph['nodes'])->mapWithKeys(fn (array $n) => [$n['id'] => (string) ($n['title'] !== '' ? $n['title'] : $n['kind'])]);

        return array_values(collect($problems)->map(fn (string $problem, string|int $id) => [
            'step' => $id === '' ? null : (string) $id,
            'title' => $id === '' ? null : ($titles[$id] ?? null),
            'problem' => $problem,
        ])->all());
    }

    /**
     * "1. Choose a machine. (Restart shop)", for errors.
     *
     * @param  list<array{step: string|null, title: string|null, problem: string}>  $problems  from problems()
     */
    public static function problemText(array $problems): string
    {
        return collect($problems)->map(fn (array $p) => ($p['step'] === null ? '' : "{$p['step']} (“{$p['title']}”): ").$p['problem'])->implode(' ');
    }

    /**
     * A rule for references: an id or a name, not a list or an object.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }

    /** @return array<string, mixed> */
    public static function workflowSchema(): array
    {
        return Schema::string('The workflow: its id or its name (as list_workflows shows).', maxLength: 120);
    }

    /** @return array<string, mixed> */
    public static function runSchema(): array
    {
        return Schema::integer('The run’s id (as list_workflow_runs or run_workflow return).', 1);
    }
}
