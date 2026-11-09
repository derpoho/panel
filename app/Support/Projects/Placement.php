<?php

namespace App\Support\Projects;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Deploys\AppReleases;

/**
 * Names and ports for something new on a machine: ones no deploy there
 * already uses.
 */
final class Placement
{
    /** A deploy name not taken on the machine, at most 60 characters. */
    public static function freeName(Machine $machine, string $base): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($base)), '-') ?: 'app';
        $taken = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->distinct()->pluck('service')->all();
        $name = substr($base, 0, 60);
        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = substr($base, 0, 56).'-'.$i;
        }

        return $name;
    }

    /** The port asked for, or the next one no deploy on the machine uses. */
    public static function freePort(Machine $machine, int $port): int
    {
        $used = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->get(['command'])
            ->map(fn (DaemonJob $job) => (int) ($job->command['job']['port'] ?? 0))
            ->all();
        while (in_array($port, $used, true)) {
            $port++;
        }

        return $port;
    }

    /**
     * What a new deploy on the machine shouldn't reuse without meaning to:
     * the names deployed there now (from its last scan, or a deploy still
     * under way), and the ports something listens on or a deploy was given.
     * The deploy dialog suggests a name and port outside these, so a second
     * PostgreSQL is a second database and not a redeploy of the first.
     *
     * @return array{services: list<string>, ports: list<int>}
     */
    public static function taken(Machine $machine): array
    {
        $services = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')
            ->whereIn('state', ['queued', 'running'])->distinct()->pluck('service')->all();
        foreach ($machine->inventory['services'] ?? [] as $found) {
            if (! is_array($found)) {
                continue;
            }
            $project = (string) ($found['details']['compose_project'] ?? '');
            if (str_starts_with($project, 'serveros-')) {
                $services[] = substr($project, strlen('serveros-'));
            } elseif ($project === '' && ($release = AppReleases::parse($found['name'] ?? null)) !== null) {
                $services[] = $release['app'];
            }
        }

        $ports = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')
            ->whereIn('service', $services)->get(['command'])
            ->map(fn (DaemonJob $job) => (int) ($job->command['job']['port'] ?? 0))
            ->all();
        foreach ($machine->inventory['listeners'] ?? [] as $listener) {
            if (is_array($listener) && isset($listener['port'])) {
                $ports[] = (int) $listener['port'];
            }
        }

        $services = array_values(array_unique(array_filter($services, fn ($name) => is_string($name) && $name !== '')));
        sort($services);
        $ports = array_values(array_unique(array_filter($ports, fn (int $port) => $port > 0)));
        sort($ports);

        return ['services' => $services, 'ports' => $ports];
    }

    /**
     * The containers a deploy runs as, from the machine's last scan: a
     * template's Compose project (serveros-{name}), or an app's release
     * containers (serveros-{name}-{commit}). Matched exactly, so shop never
     * picks up shop-db's or shop-staging's containers.
     *
     * @return list<array<string, mixed>>
     */
    public static function containers(Machine $machine, string $name): array
    {
        $release = '/^serveros-'.preg_quote($name, '/').'-[0-9a-f]{7,12}$/';
        $found = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (! is_array($service)) {
                continue;
            }
            $project = $service['details']['compose_project'] ?? null;
            if ($project === "serveros-{$name}" || ($project === null && preg_match($release, (string) ($service['name'] ?? '')) === 1)) {
                $found[] = $service;
            }
        }

        return $found;
    }

    /**
     * The newest deploy of a name that hasn't failed: one still queued or
     * running counts, so two changes made one after another build on each
     * other instead of the second undoing the first. Code deploys win over
     * template ones under the same name, as on the app's page.
     */
    public static function latestDeploy(Machine $machine, string $name): ?DaemonJob
    {
        $jobs = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $name)
            ->whereIn('state', ['queued', 'running', 'succeeded'])
            ->latest()
            ->limit(20)
            ->get();

        return $jobs->first(fn (DaemonJob $job) => filled($job->command['job']['repo'] ?? null)) ?? $jobs->first();
    }
}
