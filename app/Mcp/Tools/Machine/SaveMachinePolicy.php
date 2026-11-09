<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\MachinePolicy;
use App\Support\Audit\AuditContext;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use Illuminate\Validation\Rule;

/**
 * Create a machine policy, or change one: what's left out keeps its value
 * (a new one starts from the default's). Saving sends it to every machine
 * that follows it, as Settings › Machine policies does.
 */
abstract class SaveMachinePolicy extends Tool
{
    /** Whether this one makes a new policy rather than changing one. */
    protected bool $creating = false;

    public function name(): string
    {
        return $this->creating ? 'create_machine_policy' : 'update_machine_policy';
    }

    public function title(): string
    {
        return $this->creating ? 'Create a machine policy' : 'Change a machine policy';
    }

    public function description(): string
    {
        $what = 'discovery (true: the daemon finds running services on its own), logs (true: service logs are sent), logIpMasking (off, partial: the last part of each IP removed, or hash: each IP swapped for a code), securityWatch (true: SSH settings and failed sign-ins are read), errorReports (true: the daemon sends its crash and error reports to ServerOS, scrubbed of secrets, hostnames and IPs), statusPageNewServices (true: a machine’s new status page shows services as they’re found) updates (automatic; approve: each daemon update waits for someone to install it; or pinned, with pinnedVersion, to stay on one release), and appliesTo: rules picking machines by their details, a list like [{"field": "tier", "value": "performance"}] with field provider, region, tier or label:<name> (a machine with any of them follows the policy unless it or its group picked one; not on the default).';

        return $this->creating
            ? 'Creates a machine policy: a name (up to 60 characters) and any of '.$what.' What you leave out starts as the workspace’s default policy has it. Give it to machines with add_machine, update_machine or a machine group’s policy. Returns the policy.'
            : 'Changes a machine policy (by id, name or "default"): its name and any of '.$what.' What you leave out stays as it is. The change is sent to every machine that follows it (machines that are offline get it when they reconnect). Returns the policy and how many machines follow it.';
    }

    public function inputSchema(): array
    {
        $properties = [
            'name' => Schema::string('The policy’s name.', maxLength: 60),
            'discovery' => Schema::boolean('Find running services on its own.'),
            'logs' => Schema::boolean('Send service logs.'),
            'logIpMasking' => Schema::string('How IP addresses in log lines are masked before they leave the machine.', MachinePolicy::MASKING),
            'securityWatch' => Schema::boolean('Read SSH settings, failed sign-ins and whether security updates are on.'),
            'errorReports' => Schema::boolean('Send the daemon’s crash and error reports to ServerOS, scrubbed of secrets, hostnames and IPs.'),
            'statusPageNewServices' => Schema::boolean('A new status page for a machine shows its services as they’re found.'),
            'updates' => Schema::string('automatic; approve to wait for someone to install each daemon update; pinned to stay on pinnedVersion.', MachinePolicy::UPDATES),
            'pinnedVersion' => Schema::string('With updates pinned: the release to stay on, like v0.1.1-pre.24.', maxLength: 40),
            'appliesTo' => Schema::array(Schema::object([
                'field' => Schema::string('provider, region, tier, or label:<name>.', maxLength: 50),
                'value' => Schema::string('The value, any case.', maxLength: 100),
            ], ['field', 'value']), 'Rules: machines with any of these details follow it. An empty list removes them.', 10),
        ];

        return $this->creating
            ? Schema::object($properties, ['name'])
            : Schema::object(['policy' => Schema::string('The policy: its id, name or "default".', maxLength: 60), ...$properties], ['policy']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return ! $this->creating;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $policy = $this->creating ? null : MachinePolicyRefs::find($context, $arguments['policy'] ?? '');
        $data = $this->validate(ServerWork::trimmed($arguments, 'name'), [
            'name' => [$this->creating ? 'required' : 'sometimes', 'string', 'max:60', Rule::unique('machine_policies', 'name')->where('workspace_id', $workspace->id)->ignore($policy?->id)],
            'discovery' => ['sometimes', 'boolean'],
            'logs' => ['sometimes', 'boolean'],
            'logIpMasking' => ['sometimes', Rule::in(MachinePolicy::MASKING)],
            'securityWatch' => ['sometimes', 'boolean'],
            'errorReports' => ['sometimes', 'boolean'],
            'statusPageNewServices' => ['sometimes', 'boolean'],
            'updates' => ['sometimes', Rule::in(MachinePolicy::UPDATES)],
            'pinnedVersion' => ['sometimes', 'nullable', 'string', Rule::exists('daemon_releases', 'version')->where('channel', 'stable')],
            'appliesTo' => ['sometimes', 'nullable', 'array', 'max:10'],
            'appliesTo.*.field' => ['required', 'string', fn ($attribute, $value, $fail) => MachineMetadata::validField((string) $value) || $fail('Fields are provider, region, tier or label:<name>.')],
            'appliesTo.*.value' => ['required', 'string', 'max:'.MachineMetadata::MAX_VALUE],
        ], ['name.unique' => 'There’s already a policy with that name.', 'pinnedVersion.exists' => 'That isn’t a released version.']);

        $settings = array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'discovery' => $data['discovery'] ?? null,
            'logs' => $data['logs'] ?? null,
            'log_ip_masking' => $data['logIpMasking'] ?? null,
            'security_watch' => $data['securityWatch'] ?? null,
            'error_reports' => $data['errorReports'] ?? null,
            'status_page_new' => $data['statusPageNewServices'] ?? null,
            'updates' => $data['updates'] ?? null,
            'pinned_version' => $data['pinnedVersion'] ?? null,
        ], fn ($value) => $value !== null);
        if (array_key_exists('appliesTo', $data)) {
            if ($policy?->is_default) {
                throw new ToolError('The default policy catches every machine nothing else picks, so it has no rules.');
            }
            /** @var list<array{field: string, value: string}> $given validated above */
            $given = $data['appliesTo'] ?? [];
            $rules = collect($given)->map(fn (array $rule) => ['field' => $rule['field'], 'value' => trim($rule['value'])])->values()->all();
            $settings['applies_to'] = $rules === [] ? null : $rules;
        }
        $updates = $settings['updates'] ?? $policy->updates ?? 'automatic';
        if ($updates === 'pinned' && ($settings['pinned_version'] ?? $policy?->pinned_version) === null) {
            throw new ToolError('Pinned updates need pinnedVersion: the release to stay on.');
        }
        if (isset($settings['updates']) && $updates !== 'pinned') {
            $settings['pinned_version'] = null;
        }

        if ($this->creating) {
            $policy = MachinePolicies::whileChanging($workspace, $workspace->machines()->pluck('id'),
                fn () => $workspace->machinePolicies()->create([...MachinePolicies::defaultFor($workspace)->settings(), ...$settings]));
            $context->record('machine_policy.create', $policy->name);

            return ['policy' => MachinePolicyRefs::present($policy, 0), 'url' => $context->url('/settings/machine-policies')];
        }

        if (count($settings) === 0) {
            throw new ToolError('Give a name or a setting to change.');
        }
        $policy->fill($settings);
        if (! $policy->isDirty()) {
            AuditContext::nothingChanged();

            return ['policy' => MachinePolicyRefs::present($policy, MachinePolicies::following($policy)->count()), 'note' => 'Nothing to change: it already had those settings.'];
        }
        $settingsChanged = $policy->isDirty(['discovery', 'logs', 'log_ip_masking', 'security_watch', 'error_reports', 'updates', 'pinned_version']);
        MachinePolicies::whileChanging($workspace, $workspace->machines()->pluck('id'), fn () => $policy->save());
        $machines = MachinePolicies::following($policy)->pluck('id');
        if ($settingsChanged) {
            MachinePolicies::push($machines);
        }
        $context->record('machine_policy.update', $policy->name, meta: ['machines' => $machines->count()]);

        return [
            'policy' => MachinePolicyRefs::present($policy, $machines->count()),
            'note' => $machines->isEmpty() ? null : 'Sent to the machines that follow it. Offline ones get it when they reconnect.',
        ];
    }
}
