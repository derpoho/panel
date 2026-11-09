<?php

namespace App\Support\Projects;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Keeps a project's private network in step with its canvas. Every machine
 * something in the project runs on is a member: it gets an address on the
 * project's /24 and a WireGuard port, and is sent the whole picture (its
 * peers, and the resources on it that apps elsewhere use). The daemon
 * makes the key and reports only the public half; once a member's key is
 * known, the others are sent their peers again.
 */
class ProjectNetwork
{
    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly ProjectLinks $links,
    ) {}

    /**
     * Bring the members in line with the machines on the canvas, then send
     * each its network: new ones join, ones with nothing left leave.
     */
    public function sync(Project $project, User $user): void
    {
        $machines = $project->nodes()->pluck('machine_id')->unique()->values();

        foreach ($project->members()->whereNotIn('machine_id', $machines)->with('machine')->get() as $gone) {
            $this->leave($gone, $user);
        }

        foreach ($machines as $machineId) {
            $this->member($project, (int) $machineId);
        }

        foreach ($project->members()->with(['machine', 'project'])->get() as $member) {
            $this->send($member, $user);
        }
    }

    /** Take the whole network down, before the project goes. */
    public function teardown(Project $project, User $user): void
    {
        foreach ($project->members()->with(['machine', 'project'])->get() as $member) {
            $this->leave($member, $user);
        }
    }

    /**
     * A network job finished. On success the machine's public key is kept,
     * and if it's new the other members learn it. Links waiting on this
     * machine's network are tried again.
     */
    public function jobFinished(DaemonJob $job): void
    {
        $spec = $job->command['job'] ?? [];
        if (($spec['action'] ?? null) !== 'up') {
            return;
        }
        $project = Project::query()->where('uid', (string) ($spec['network'] ?? ''))->first();
        $member = $project?->members()->where('machine_id', $job->machine_id)->with(['machine', 'project'])->first();
        if ($member === null) {
            return;
        }

        if ($job->state !== 'succeeded') {
            $member->forceFill([
                'state' => 'failed',
                'error' => mb_substr((string) ($job->error['message'] ?? 'The machine couldn’t set up the private network.'), 0, 500),
            ])->save();

            return;
        }

        $key = $job->result['public_key'] ?? null;
        $changed = is_string($key) && $key !== $member->public_key;
        $member->forceFill([
            'state' => 'up',
            'error' => null,
            'public_key' => is_string($key) ? $key : $member->public_key,
        ])->save();

        $user = $job->user;
        if ($user === null) {
            return;
        }

        if ($changed) {
            foreach ($project->members()->whereKeyNot($member->id)->with(['machine', 'project'])->get() as $other) {
                $this->send($other, $user);
            }
        }

        $this->links->retry($project, $user);
    }

    /**
     * The network job for one member: its address and port, every other
     * member it can reach, and the resources on it apps on other machines
     * (or in containers on this one) are pointed at.
     *
     * @return array<string, mixed>
     */
    public function spec(ProjectMachine $member): array
    {
        $project = $member->project;
        $others = $project->members()->whereKeyNot($member->id)->with('machine')->get();

        return [
            'type' => 'network',
            'network' => $project->uid,
            'action' => 'up',
            'address' => $member->address().'/24',
            'listen_port' => $member->listen_port,
            'peers' => $others
                ->filter(fn (ProjectMachine $other) => filled($other->public_key))
                ->map(fn (ProjectMachine $other) => array_filter([
                    'public_key' => $other->public_key,
                    'address' => $project->prefix().'.'.$other->host.'/32',
                    'endpoint' => self::endpoint($other),
                ], fn ($value) => $value !== null))
                ->values()
                ->all(),
            'expose' => $this->exposed($member)->values()->all(),
        ];
    }

    /**
     * The resources on this member that a link uses, or that a copy in
     * another environment is filled from, each offered on its network
     * address at the port it was deployed on.
     *
     * @return Collection<int, array{port: int, target: string}>
     */
    private function exposed(ProjectMachine $member): Collection
    {
        $used = ProjectLink::query()
            ->where('project_id', $member->project_id)
            ->with('resource')
            ->get()
            ->map(fn (ProjectLink $link) => $link->resource);
        // Databases a copy in another environment is filled from, only while
        // a copy wants their data.
        $copied = ProjectNode::query()
            ->where('project_id', $member->project_id)
            ->whereIn('id', ProjectNode::query()->where('project_id', $member->project_id)->whereNotNull('origin_id')->whereNotNull('data_state')->whereIn('template', ProjectData::COPYABLE)->select('origin_id'))
            ->get();

        return $used->concat($copied)
            ->filter(fn (ProjectNode $resource) => $resource->machine_id === $member->machine_id)
            ->unique('id')
            ->map(fn (ProjectNode $resource) => $this->links->port($resource))
            ->filter()
            ->unique()
            ->map(fn (int $port) => ['port' => $port, 'target' => "127.0.0.1:{$port}"]);
    }

    /** The member for a machine, given an address and a port if new. */
    private function member(Project $project, int $machineId): ProjectMachine
    {
        $existing = $project->members()->where('machine_id', $machineId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $hosts = $project->members()->pluck('host')->all();
        $host = 2;
        while (in_array($host, $hosts, true)) {
            $host++;
        }
        if ($host > 254) {
            throw new RuntimeException('A project can have up to 253 machines.');
        }

        // A port no other project uses on this machine.
        $ports = ProjectMachine::query()->where('machine_id', $machineId)->pluck('listen_port')->all();
        $port = ProjectMachine::FIRST_PORT;
        while (in_array($port, $ports, true)) {
            $port++;
        }

        $member = new ProjectMachine;
        $member->forceFill([
            'project_id' => $project->id,
            'machine_id' => $machineId,
            'host' => $host,
            'listen_port' => $port,
            'state' => 'pending',
        ])->save();

        return $member;
    }

    private function send(ProjectMachine $member, User $user): void
    {
        $machine = $member->machine;
        if ($this->queue->unavailable($machine) !== null) {
            $member->forceFill(['state' => $member->public_key === null ? 'offline' : $member->state])->save();

            return;
        }

        try {
            $this->queue->queue($machine, $user, 'network', $this->spec($member), null, 300);
        } catch (RuntimeException $e) {
            $member->forceFill(['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
    }

    private function leave(ProjectMachine $member, User $user): void
    {
        $machine = $member->machine;
        if ($this->queue->unavailable($machine) === null) {
            try {
                $this->queue->queue($machine, $user, 'network', [
                    'type' => 'network',
                    'network' => $member->project->uid,
                    'action' => 'down',
                ], null, 120);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
        $member->delete();
    }

    /** Where peers reach a machine: its public address and network port. */
    private static function endpoint(ProjectMachine $member): ?string
    {
        $address = (string) ($member->machine->ip_address ?? '');
        if (! filter_var($address, FILTER_VALIDATE_IP) || in_array($address, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return (str_contains($address, ':') ? "[{$address}]" : $address).':'.$member->listen_port;
    }

    /** Whether this machine's side of the network is up. */
    public static function isUp(Project $project, Machine $machine): bool
    {
        return $project->members()->where('machine_id', $machine->id)->where('state', 'up')->exists();
    }
}
