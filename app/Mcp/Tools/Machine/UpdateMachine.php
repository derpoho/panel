<?php

namespace App\Mcp\Tools\Machine;

use App\Enums\MachineEnvironment;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use Illuminate\Validation\Rule;

/**
 * Rename a machine or move it to another environment, as its settings
 * page does.
 */
final class UpdateMachine extends Tool
{
    public function name(): string
    {
        return 'update_machine';
    }

    public function title(): string
    {
        return 'Rename a machine or change its environment';
    }

    public function description(): string
    {
        return 'Renames a machine, changes its environment (production, staging or development) and/or the machine policy it follows, like its settings page. What you leave out stays as it is. A name or environment only changes how ServerOS shows the machine (its hostname stays). A policy (an id or name from list_machine_policies, or "inherit" to follow its group’s policy, else the workspace’s default) is sent to the machine’s daemon: what it discovers, whether it sends logs and how IPs are masked, and how it updates. Returns the machine and what changed; each change is noted in the activity log.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'name' => Schema::string('The new name (up to 255 characters).', maxLength: 255),
            'environment' => Schema::string('The new environment.', array_map(fn (MachineEnvironment $e) => $e->value, MachineEnvironment::cases())),
            'policy' => Schema::string('The machine policy it follows: an id or name from list_machine_policies, or "inherit" to follow its group’s, else the default.', maxLength: 60),
            'provider' => Schema::string('Its provider. Empty: back to what detection says.', maxLength: 60),
            'region' => Schema::string('Its region. Empty: back to what detection says.', maxLength: 60),
            'tier' => Schema::string('Its tier. Empty: none.', maxLength: 60),
            'labels' => ['type' => 'object', 'description' => 'Labels to set, like {"customer": "acme"}; a null or empty value takes that label off. Others stay.', 'additionalProperties' => ['type' => ['string', 'null']]],
        ], ['machine']);
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
        $data = $this->validate(ServerWork::trimmed($arguments, 'name'), [
            'machine' => ['required', ServerWork::reference()],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'environment' => ['sometimes', 'required', Rule::enum(MachineEnvironment::class)],
            'policy' => ['sometimes', 'required'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'labels' => ['sometimes', 'array'],
        ]);
        $metadata = array_intersect_key($data, array_flip(['provider', 'region', 'tier', 'labels']));
        if (! isset($data['name']) && ! isset($data['environment']) && ! isset($data['policy']) && $metadata === []) {
            throw new ToolError('Give a new name, environment, policy or details.');
        }

        $machine = $context->machine($data['machine']);
        $policyBefore = isset($data['policy']) ? MachinePolicies::for($machine) : null;
        if (isset($data['policy'])) {
            $machine->forceFill(['machine_policy_id' => mb_strtolower(trim((string) $data['policy'])) === 'inherit' ? null : MachinePolicyRefs::find($context, $data['policy'])->id]);
        }
        $machine->forceFill([
            'name' => isset($data['name']) ? trim($data['name']) : $machine->name,
            'environment' => isset($data['environment']) ? MachineEnvironment::from($data['environment']) : $machine->environment,
        ]);

        $changed = [];
        if ($machine->isDirty('name')) {
            $changed['name'] = ['from' => $machine->getOriginal('name'), 'to' => $machine->name];
            $context->record('machine.rename', $machine->name, $machine, ['from' => $machine->getOriginal('name')]);
        }
        if ($machine->isDirty('environment')) {
            $changed['environment'] = ['from' => $machine->getOriginal('environment')?->value, 'to' => $machine->environment->value];
            $context->record('machine.environment', $machine->name, $machine, ['to' => $machine->environment->value]);
        }
        $policyBefore ??= $metadata === [] ? null : MachinePolicies::for($machine);
        $fields = MachineMetadata::apply($machine, $metadata);
        if ($fields !== []) {
            $changed['details'] = $fields;
            $context->record('machine.metadata', $machine->name, $machine, ['fields' => $fields]);
        }
        $machine->save();
        if ($policyBefore !== null && ($machine->wasChanged('machine_policy_id') || $fields !== [])) {
            $machine->unsetRelation('policy');
            $policyAfter = MachinePolicies::for($machine);
            if ($machine->wasChanged('machine_policy_id') || $policyAfter->id !== $policyBefore->id) {
                $changed['policy'] = ['from' => $policyBefore->name, 'to' => $policyAfter->name];
                $context->record('machine.policy', $machine->name, $machine, ['from' => $policyBefore->name, 'to' => $policyAfter->name]);
            }
            if ($policyAfter->id !== $policyBefore->id) {
                $changed['policy']['sentToMachine'] = MachinePolicies::sync($machine);
            }
        }
        if ($changed === []) {
            AuditContext::nothingChanged();
        }

        return [
            'changed' => $changed === [] ? null : $changed,
            'note' => $changed === [] ? 'Nothing to change: it already had that name, environment and policy.' : null,
            'machine' => MachineView::row($machine, $context, $machine->machine_group_id === null ? null : $context->workspace->machineGroups()->find($machine->machine_group_id)),
        ];
    }
}
