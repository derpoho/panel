<?php

namespace App\Support\Workflows;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Workflow;
use App\Models\Workspace;

/**
 * What the editor offers and what a test run starts with, shared by the
 * dashboard and the MCP tools so both see the same choices and a run by
 * hand means the same thing from either.
 */
final class WorkflowInputs
{
    /**
     * What the step settings can choose from: each machine with the
     * services a step can act on and the apps deployed to it, the
     * monitors, and the projects with their environments.
     *
     * @return array{machines: list<array{id: int, name: string, services: list<array{key: string, name: string, managed: bool, container: bool}>, deploys: list<string>}>, monitors: list<array{id: int, name: string}>, projects: list<array{id: int, name: string, environments: list<array{id: int, name: string, source: int|null}>}>}
     */
    public static function options(Workspace $workspace): array
    {
        $machines = $workspace->machines()->orderBy('name')->get();
        $deploys = DaemonJob::query()->whereIn('machine_id', $machines->modelKeys())->where('kind', 'deploy')
            ->whereNotNull('service')->select(['machine_id', 'service'])->distinct()->get()->groupBy('machine_id');

        return [
            'machines' => array_values($machines->map(fn (Machine $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'services' => self::services($m),
                'deploys' => array_values(($deploys[$m->id] ?? collect())->map(fn (DaemonJob $job) => $job->service)->filter()->unique()->all()),
            ])->all()),
            'monitors' => array_values($workspace->monitors()->orderBy('name')->get(['id', 'name'])->map(fn (Monitor $m) => ['id' => $m->id, 'name' => $m->name])->all()),
            // Projects and their environments, for copying data and promoting.
            'projects' => array_values($workspace->projects()->with('environments')->orderBy('name')->get()->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                // Pull request previews come and go, so a workflow can't name one.
                'environments' => array_values($p->environments->whereNull('pull_request')->map(fn (ProjectEnvironment $e) => ['id' => $e->id, 'name' => $e->name, 'source' => $e->source_id])->values()->all()),
            ])->all()),
        ];
    }

    /**
     * A test run of an event workflow pretends the event happened to what
     * its trigger names, so "the service that stopped" means something.
     * Only ever this workspace's machines.
     *
     * @return array<string, mixed>
     */
    public static function sampleData(Workflow $workflow): array
    {
        $rules = $workflow->triggerRules();
        $machines = $workflow->workspace->machines();
        $machine = isset($rules['machine_id']) && $rules['machine_id'] !== '' ? (clone $machines)->find((int) $rules['machine_id']) : null;
        $machine ??= (clone $machines)->orderBy('name')->first();
        $services = $machine?->inventory['services'] ?? [];
        $service = collect(is_array($services) ? $services : [])->first(fn ($s) => is_array($s) && (($rules['service'] ?? '') === '' || in_array($rules['service'], [$s['key'] ?? null, $s['name'] ?? null], true)));

        return array_filter([
            'test' => true,
            'machine_id' => $machine?->id,
            'machine' => $machine?->name,
            'service_key' => $service['key'] ?? null,
            'service' => $workflow->trigger === 'deploy.finished'
                ? ($rules['service'] ?? DaemonJob::query()->where('machine_id', $machine?->id)->where('kind', 'deploy')->latest()->value('service'))
                : ($service['name'] ?? null),
            'outcome' => $workflow->trigger === 'deploy.finished' ? 'succeeded' : null,
            'ref' => $workflow->trigger === 'webhook' ? 'refs/heads/main' : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The machine's Docker and systemd services from its latest scan.
     *
     * @return list<array{key: string, name: string, managed: bool, container: bool}>
     */
    private static function services(Machine $machine): array
    {
        $inventory = $machine->inventory['services'] ?? [];
        $services = [];
        foreach (is_array($inventory) ? $inventory : [] as $s) {
            if (! is_array($s) || ! is_string($s['key'] ?? null) || preg_match('/^(docker|systemd):/', $s['key']) !== 1) {
                continue;
            }
            $services[] = [
                'key' => $s['key'],
                'name' => (string) ($s['name'] ?? $s['key']),
                'managed' => ($s['details']['managed'] ?? null) === 'true',
                'container' => str_starts_with($s['key'], 'docker:'),
            ];
        }

        return $services;
    }
}
