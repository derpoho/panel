<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;
use App\Models\Machine;

/**
 * Apps ServerOS builds from code run in containers named
 * serveros-<app>-<first 12 of the commit>, one per version. A deploy starts
 * the new version beside the old one, then stops the old one and keeps it
 * a while for rollback. So the same app shows up as several containers,
 * each with its own id.
 *
 * This works out which one is live (the latest successful deploy's), so
 * the panel can show one service per app at a stable address
 * (app:<name>), and treat the others as retired versions: not listed, not
 * alerted on when the deploy stops them.
 */
final class AppReleases
{
    /**
     * Worked out once per loaded machine; a fresh load asks again.
     *
     * @var \WeakMap<Machine, array<string, string>>|null
     */
    private static ?\WeakMap $live = null;

    /** @return array{app: string, commit: string}|null */
    public static function parse(?string $containerName): ?array
    {
        if (! preg_match('/^\/?serveros-(.+)-([0-9a-f]{12})$/', (string) $containerName, $match)) {
            return null;
        }

        return ['app' => $match[1], 'commit' => $match[2]];
    }

    /**
     * Each app's live version: the commit of its latest successful deploy
     * from code, or rollback, whichever came last (first 12 characters).
     *
     * @return array<string, string>
     */
    public static function live(Machine $machine): array
    {
        self::$live ??= new \WeakMap;

        return self::$live[$machine] ??= DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->whereIn('kind', ['deploy', 'rollback'])
            ->where('state', 'succeeded')
            ->orderBy('created_at')
            ->get(['kind', 'service', 'command', 'result'])
            ->filter(fn (DaemonJob $job) => is_string($job->service) && ($job->kind === 'rollback'
                ? is_string($job->result['commit'] ?? null)
                : filled($job->command['job']['repo'] ?? null)))
            ->mapWithKeys(fn (DaemonJob $job) => [$job->service => substr((string) ($job->kind === 'rollback'
                ? $job->result['commit']
                : ($job->command['job']['commit'] ?? '')), 0, 12)])
            ->all();
    }

    /**
     * A version a later deploy replaced. Containers of apps with no deploy
     * on record (or not from ServerOS at all) are never retired.
     *
     * @param  array<string, mixed>  $service  an inventory entry
     */
    public static function retired(Machine $machine, array $service): bool
    {
        $release = self::parse($service['name'] ?? null);
        if ($release === null) {
            return false;
        }
        $live = self::live($machine)[$release['app']] ?? null;

        return $live !== null && $live !== $release['commit'];
    }

    /**
     * The machine's services as people should see them: retired versions
     * left out, each live app marked with its stable name, and any name
     * someone gave it in the panel (label).
     *
     * @param  list<array<string, mixed>>|null  $services  defaults to the latest inventory
     * @return list<array<string, mixed>>
     */
    public static function visible(Machine $machine, ?array $services = null): array
    {
        $visible = [];
        foreach ($services ?? ($machine->inventory['services'] ?? []) as $service) {
            if (! is_array($service) || self::retired($machine, $service)) {
                continue;
            }
            $release = self::parse($service['name'] ?? null);
            if ($release !== null && isset(self::live($machine)[$release['app']])) {
                $service['app'] = $release['app'];
            }
            $service['label'] = ServiceLabels::for($machine, $service);
            $service['found'] = ContainerNames::found($service);
            $service['icon'] = ServiceLabels::iconUrl($machine, $service);
            $service['hidden'] = ServiceLabels::isHidden($machine, $service);
            $visible[] = $service;
        }

        return $visible;
    }

    /**
     * The inventory entry behind a stable app address (app:<name>): the
     * live version, or failing that the first one listed.
     *
     * @return array<string, mixed>|null
     */
    public static function find(Machine $machine, string $app): ?array
    {
        $live = self::live($machine)[$app] ?? null;
        $fallback = null;
        foreach ($machine->inventory['services'] ?? [] as $service) {
            $release = is_array($service) ? self::parse($service['name'] ?? null) : null;
            if ($release === null || $release['app'] !== $app) {
                continue;
            }
            if ($release['commit'] === $live) {
                return $service;
            }
            $fallback ??= $service;
        }

        return $fallback;
    }
}
