<?php

namespace App\Mcp\Tools\Machine;

use App\Actions\Machines\CreateJoinCommand;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use RuntimeException;

/**
 * Connect a new server, as the Connect server dialog does: a one-time
 * install command that enrols it in this workspace. Provisioning scripts
 * call it through the API (POST /api/v1/machines) for each new server and
 * pass the token on, so the API key itself never goes onto a machine. A
 * policy and a group can come with it, so the machine follows them from
 * its first start.
 */
final class AddMachine extends Tool
{
    /** How long the command works for by default, as DaemonConnectController. */
    private const EXPIRES_MINUTES = 15;

    /** The longest a provisioning script can ask for: a day. */
    private const MAX_EXPIRES_MINUTES = 1440;

    public function name(): string
    {
        return 'add_machine';
    }

    public function title(): string
    {
        return 'Connect a new machine';
    }

    public function description(): string
    {
        return 'Starts connecting a new machine (Linux, with root access) to this workspace. Returns a one-line install command to run on that machine as root: it installs the ServerOS daemon, which enrols the machine here. The command works once, within 15 minutes (or expiresInMinutes, up to 1440, for a server that is still being provisioned), and holds a secret: give it to the person to run (or run it there only if they ask you to), and don’t store or share it elsewhere. '
            .'Give policy (a machine policy’s id or name, from list_machine_policies) to choose what the machine collects and sends from its first start: whether services are discovered, logs are sent, IPs are masked and the daemon updates itself. Without one it follows its group’s policy, else the workspace’s default. Give group (a machine group’s id or name) to put it in that group as it joins, and provider, region, tier and labels ({"customer": "acme"}) to tag it as it joins; policies can apply by those. '
            .'Then call get_machine_connection_status with the enrolmentId to see it arrive (waiting → enrolled → connected → discovered). Refused when the workspace has reached its plan’s machine limit (machines waiting to connect count, until their command expires). '
            .'Also returns the bare token, for provisioning scripts that run the installer themselves: curl -fsSL '.config('daemon.install_url').' | sudo bash -s -- --token=TOKEN.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'expiresInMinutes' => Schema::integer('How long the command works for, in minutes: 15 by default, up to 1440 (a day). Until it’s used or expires, it holds one of the plan’s machine places.', 1, self::MAX_EXPIRES_MINUTES),
            'policy' => Schema::string('The machine policy it follows: an id or name from list_machine_policies, or "default". Leave it out to follow its group’s policy, else the workspace’s default.', maxLength: 60),
            'group' => Schema::string('A machine group to put it in as it joins: an id or name from list_machine_groups.', maxLength: 60),
            'provider' => Schema::string('Its provider, as you call it (detection fills it in when left out).', maxLength: 60),
            'region' => Schema::string('Its region, like lon1 or eu-west.', maxLength: 60),
            'tier' => Schema::string('Its tier, like performance or budget.', maxLength: 60),
            'labels' => ['type' => 'object', 'description' => 'Labels of your own, like {"customer": "acme"}. Up to 20.', 'additionalProperties' => ['type' => ['string', 'null']]],
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    #[NotAudited('Only makes a join command; the machine joining is recorded when it enrols, credited to whoever made the command')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'expiresInMinutes' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_EXPIRES_MINUTES],
            'policy' => ['sometimes', 'nullable'],
            'group' => ['sometimes', 'nullable'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
            'labels' => ['sometimes', 'nullable', 'array'],
        ]);
        // Its details, checked now and set as it enrols.
        $metadata = array_filter([
            'provider' => trim((string) ($data['provider'] ?? '')) ?: null,
            'region' => trim((string) ($data['region'] ?? '')) ?: null,
            'tier' => trim((string) ($data['tier'] ?? '')) ?: null,
        ]);
        if (filled($data['labels'] ?? null)) {
            $metadata['labels'] = MachineMetadata::labels($data['labels']);
        }
        $minutes = (int) ($data['expiresInMinutes'] ?? self::EXPIRES_MINUTES);
        ServerWork::throttle($context, 'connect', 10);

        $group = filled($data['group'] ?? null) ? MachineGroups::find($context, $data['group']) : null;
        $policy = filled($data['policy'] ?? null) ? MachinePolicyRefs::find($context, $data['policy']) : null;

        $workspace = $context->workspace;
        try {
            $created = app(CreateJoinCommand::class)->create($workspace, $context->user, $minutes, policy: $policy, group: $group, metadata: $metadata);
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }

        if ($created === null) {
            throw new ToolError('This workspace has reached its machine limit.'.($workspace->machineLimit() === 0 ? ' It has no plan: choose one in Billing to connect machines.' : ' Remove a machine, or move to a bigger plan in Billing.'));
        }

        // What it'll follow: the one chosen, else its group's, else the default.
        $follows = $policy ?? $group->policy ?? MachinePolicies::defaultFor($workspace);

        return [
            'command' => $created['command'],
            'token' => $created['token'],
            'expiresAt' => $created['enrolment']->expires_at->toIso8601String(),
            'enrolmentId' => $created['enrolment']->id,
            'policy' => MachinePolicyRefs::present($follows),
            'group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name],
            'details' => $metadata === [] ? null : $metadata,
            'next' => 'Run the command on the new machine as root. Then call get_machine_connection_status with this enrolmentId.',
        ];
    }
}
