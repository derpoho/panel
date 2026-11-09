<?php

namespace App\Support\Machines;

use App\Jobs\ApplyMachinePolicy;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePolicy;
use App\Models\Workspace;
use App\Support\DaemonGatewayClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Which policy a machine follows, and getting it onto the machine.
 *
 * A machine follows its own pick, else its group's, else the first policy
 * (oldest first) whose rules match its metadata (tier: performance, say),
 * else the workspace's default. The policy reaches the daemon three ways:
 * with its enrolment (so it holds from the first start), in a `reconfigure`
 * whenever the policy or the machine's place changes, and again when the
 * machine says hello with something different, so one that was offline
 * catches up.
 */
final class MachinePolicies
{
    /**
     * The workspace's default policy, made the first time it's needed. It
     * starts with everything on and updates installing on their own, so
     * making it never changes what a machine does; the welcome flow sets a
     * new workspace's own.
     */
    public static function defaultFor(Workspace $workspace): MachinePolicy
    {
        $policy = $workspace->machinePolicies()->where('is_default', true)->orderBy('id')->first();
        if ($policy !== null) {
            return $policy;
        }

        return DB::transaction(function () use ($workspace) {
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();

            return $workspace->machinePolicies()->where('is_default', true)->first()
                ?? $workspace->machinePolicies()->create(['name' => __('Default'), 'is_default' => true, ...MachinePolicy::preset(null)]);
        });
    }

    /** The policy the machine follows. */
    public static function for(Machine $machine): MachinePolicy
    {
        return self::resolve($machine)['policy'];
    }

    /**
     * The policy the machine follows and why: its own pick, its group's, or
     * the workspace's default.
     *
     * @param  Collection<int, MachinePolicy>|null  $ruled  the workspace's policies with rules, oldest first, when the caller has them
     * @return array{policy: MachinePolicy, from: 'machine'|'group'|'rule'|'default', group: MachineGroup|null}
     */
    public static function resolve(Machine $machine, ?Collection $ruled = null, ?MachinePolicy $default = null): array
    {
        if ($machine->machine_policy_id !== null && ($own = $machine->policy) !== null) {
            return ['policy' => $own, 'from' => 'machine', 'group' => null];
        }

        $group = $machine->machine_group_id === null ? null : $machine->group;
        if ($group?->machine_policy_id !== null && ($groups = $group->policy) !== null) {
            return ['policy' => $groups, 'from' => 'group', 'group' => $group];
        }

        foreach ($ruled ?? self::ruled($machine->workspace_id) as $policy) {
            if ($policy->appliesTo($machine)) {
                return ['policy' => $policy, 'from' => 'rule', 'group' => null];
            }
        }

        return ['policy' => $default ?? self::defaultFor($machine->workspace), 'from' => 'default', 'group' => null];
    }

    /**
     * The workspace's policies that pick machines by their metadata, in the
     * order they're tried.
     *
     * @return Collection<int, MachinePolicy>
     */
    public static function ruled(int $workspaceId): Collection
    {
        return MachinePolicy::query()->where('workspace_id', $workspaceId)->whereNotNull('applies_to')->orderBy('id')->get()
            ->filter(fn (MachinePolicy $policy) => ($policy->applies_to ?? []) !== [])->values();
    }

    /**
     * The machines that follow this policy, whichever way. Worked out per
     * machine, since rules match on metadata.
     *
     * @return Builder<Machine>
     */
    public static function following(MachinePolicy $policy): Builder
    {
        $workspace = $policy->workspace;
        $ids = array_keys(array_filter(self::effective($workspace, null), fn (int $id) => $id === $policy->id));

        return Machine::query()->where('workspace_id', $policy->workspace_id)->whereIn('id', $ids);
    }

    /**
     * What the daemon is told at enrolment: what the policy says, for
     * daemons that read it (older ones ignore it, and hello catches up).
     *
     * @return array<string, bool|string>
     */
    public static function enrolSettings(Machine $machine): array
    {
        return self::for($machine)->toDaemon();
    }

    /**
     * Send these machines their policy in the background: after a policy
     * changes, or a machine moves group or picks another.
     *
     * @param  iterable<Machine|int>  $machines
     */
    public static function push(iterable $machines): void
    {
        foreach ($machines as $machine) {
            ApplyMachinePolicy::dispatch($machine instanceof Machine ? $machine->id : (int) $machine)->afterCommit();
        }
    }

    /**
     * Make a change that can move machines to another policy (a group's
     * policy or members, a group deleted), then send their new policy to
     * the ones whose policy did change.
     *
     * @template T
     *
     * @param  iterable<int>  $machineIds  the machines the change can touch
     * @param  callable(): T  $change
     * @return T
     */
    public static function whileChanging(Workspace $workspace, iterable $machineIds, callable $change): mixed
    {
        $ids = array_values(collect($machineIds)->map(fn ($id) => (int) $id)->unique()->all());
        $before = self::effective($workspace, $ids);
        $result = $change();
        $after = self::effective($workspace, $ids);
        self::push(array_keys(array_diff_assoc($after, $before)));

        return $result;
    }

    /**
     * Each machine's policy id: these machines, or all of the workspace's.
     *
     * @param  list<int>|null  $ids  null for all of them
     * @return array<int, int>
     */
    public static function effective(Workspace $workspace, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        $ruled = self::ruled($workspace->id);
        $default = self::defaultFor($workspace);

        return $workspace->machines()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->with(['policy', 'group.policy'])->get()
            ->mapWithKeys(fn (Machine $machine) => [$machine->id => self::resolve($machine, $ruled, $default)['policy']->id])
            ->all();
    }

    /**
     * Tell the machine's daemon what its policy says. With what the daemon
     * last reported (its hello), only what differs is sent, and only what
     * it reports, so a daemon that can't do something isn't asked every
     * time it connects. Returns whether the daemon has it now; when it
     * couldn't be reached, the machine is marked to get it at its next
     * hello.
     *
     * @param  array<string, mixed>|null  $reported
     */
    public static function sync(Machine $machine, ?array $reported = null): bool
    {
        $policy = self::for($machine);
        $wanted = $policy->toDaemon();
        if ($reported !== null) {
            $wanted = array_filter($wanted, fn ($value, $key) => array_key_exists($key, $reported) && $reported[$key] !== $value, ARRAY_FILTER_USE_BOTH);
        }

        // The panel's own record of masking follows the policy, as the
        // collected-data pages read it.
        $machine->forceFill(['log_ip_masking' => $policy->log_ip_masking]);

        if ($wanted === []) {
            $machine->forceFill(['policy_pending' => false])->save();

            return true;
        }

        try {
            if ($machine->uid === null) {
                throw new RuntimeException('Not connected yet.');
            }
            app(DaemonGatewayClient::class)->control($machine, ['type' => 'reconfigure', ...$wanted]);
            $machine->forceFill(['policy_pending' => false])->save();

            return true;
        } catch (RuntimeException) {
            $machine->forceFill(['policy_pending' => true])->save();

            return false;
        }
    }
}
