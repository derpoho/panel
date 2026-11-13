<?php

namespace App\Support\Machines;

use App\Http\Controllers\MachineUpdatesController;
use App\Jobs\CheckMachineUpdates;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PackageUpdates
{
    public const array INSTALL_KINDS = ['package_install', 'package_install_versions', 'package_add'];

    public const array KINDS = ['package_updates', 'package_install', 'package_install_versions', 'package_hold', 'package_add'];

    public const int FOLLOW_UP_SECONDS = 20;

    public const int CHECK_TIMEOUT = 300;

    // No leading dash (an option) or trailing one (apt reads "nginx-" as remove nginx).
    public const string NAME_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9+._:~-]*[A-Za-z0-9+._:~])?$/';

    public const string VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.+~:_-]*$/';

    public static function ingest(DaemonJob $job, bool $followUp = true): void
    {
        if ($job->state !== 'succeeded' || ! in_array($job->kind, self::KINDS, true)) {
            return;
        }
        $machine = $job->machine;
        $result = is_array($job->result) ? $job->result : [];

        match (true) {
            $job->kind === 'package_hold' => self::summarise($machine, $result),
            $job->kind === 'package_updates' && ! (bool) ($result['applied'] ?? false) => self::replace($machine, $result, $job),
            default => self::installed($machine, $job, $result, $followUp),
        };
    }

    /** @param  array<string, mixed>  $result */
    private static function replace(Machine $machine, array $result, DaemonJob $job): void
    {
        [$rows, $complete] = self::rows($result);
        $now = now();

        DB::transaction(function () use ($machine, $rows, $complete, $result, $job, $now) {
            MachinePackageUpdate::query()->where('machine_id', $machine->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                MachinePackageUpdate::query()->insert(array_map(fn (array $row) => [
                    ...$row,
                    'machine_id' => $machine->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
            $machine->forceFill(['updates_checked_at' => $job->finished_at ?? $now, 'updates_complete' => $complete])->save();
            self::summarise($machine, $result);
        });
    }

    /** @param  array<string, mixed>  $result */
    private static function installed(Machine $machine, DaemonJob $job, array $result, bool $followUp): void
    {
        $spec = is_array($job->command['job'] ?? null) ? $job->command['job'] : [];
        $rows = MachinePackageUpdate::query()->where('machine_id', $machine->id);

        if ($job->kind === 'package_add') {
        } elseif ($job->kind === 'package_install_versions') {
            $installed = self::versions(is_array($result['installed'] ?? null) ? $result['installed'] : (is_array($spec['packages'] ?? null) ? $spec['packages'] : []));
            foreach ($installed as $name => $version) {
                (clone $rows)->where('name', $name)->where('candidate', $version)->delete();
                (clone $rows)->where('name', $name)->update(['installed' => $version, 'updated_at' => now()]);
            }
        } elseif ($job->kind === 'package_install') {
            $names = array_values(array_filter(is_array($spec['packages'] ?? null) ? $spec['packages'] : [], 'is_string'));
            $names = [...$names, ...array_map(fn (string $n) => explode(':', $n)[0], $names)];
            (clone $rows)->whereIn('name', array_unique($names))->delete();
        } elseif ((bool) ($spec['security_only'] ?? false)) {
            (clone $rows)->where('security', true)->delete();
        } else {
            (clone $rows)->delete();
        }

        self::summarise($machine, $result);

        if ($followUp) {
            CheckMachineUpdates::dispatch($machine->id)->delay(now()->addSeconds(self::FOLLOW_UP_SECONDS));
        }
    }

    /** @param  array<string, mixed>  $result */
    private static function summarise(Machine $machine, array $result): void
    {
        $counts = MachinePackageUpdate::query()->where('machine_id', $machine->id)
            ->selectRaw('count(*) as total, sum(case when security then 1 else 0 end) as security')
            ->first();

        $changes = [
            'updates_count' => (int) ($counts->total ?? 0),
            'security_updates_count' => (int) ($counts->security ?? 0),
        ];
        if (array_key_exists('reboot_required', $result)) {
            $changes['reboot_required'] = (bool) $result['reboot_required'];
        }
        if (($restarts = StaleServices::fromResult($result)) !== null) {
            $changes['restarts'] = $restarts;
        }
        if (is_array($result['held'] ?? null)) {
            $held = array_values(array_unique(array_map(fn (string $n) => mb_substr($n, 0, 200), array_filter($result['held'], 'is_string'))));
            sort($held);
            $changes['held_packages'] = array_slice($held, 0, 1000);
        }
        if (is_array($result['os'] ?? null)) {
            $os = $result['os'];
            $text = fn (string $key, int $max) => is_string($os[$key] ?? null) && $os[$key] !== '' ? mb_substr($os[$key], 0, $max) : null;
            $changes['os_release'] = ['id' => $text('id', 40), 'version_id' => $text('version_id', 40), 'pretty_name' => $text('pretty_name', 120)];
        }

        $machine->forceFill($changes)->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0: list<array{name: string, arch: string|null, installed: string|null, candidate: string, security: bool}>, 1: bool}
     */
    public static function rows(array $result): array
    {
        $rows = [];
        if (is_array($result['packages'] ?? null)) {
            foreach ($result['packages'] as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['candidate'] ?? null)
                    || $package['name'] === '' || $package['candidate'] === '') {
                    continue;
                }
                $rows[] = [
                    'name' => mb_substr($package['name'], 0, 200),
                    'arch' => is_string($package['arch'] ?? null) && $package['arch'] !== '' ? mb_substr($package['arch'], 0, 40) : null,
                    'installed' => is_string($package['installed'] ?? null) && $package['installed'] !== '' ? mb_substr($package['installed'], 0, 128) : null,
                    'candidate' => mb_substr($package['candidate'], 0, 128),
                    'security' => (bool) ($package['security'] ?? false),
                ];
            }

            return [$rows, true];
        }

        $security = array_values(array_filter(is_array($result['security'] ?? null) ? $result['security'] : [], 'is_string'));
        foreach (array_filter(is_array($result['upgradable'] ?? null) ? $result['upgradable'] : [], 'is_string') as $line) {
            $package = MachineUpdatesController::package($line, $security);
            if ($package['to'] === null || $package['name'] === '') {
                continue;
            }
            $arch = preg_match('#^[^/\s]+/\S+\s+\S+\s+(\S+)#', $line, $m) === 1 ? $m[1]
                : (preg_match('/^\S+\.(x86_64|aarch64|noarch|i686)\s/', $line, $m) === 1 ? $m[1] : null);
            $rows[] = [
                'name' => mb_substr($package['name'], 0, 200),
                'arch' => $arch,
                'installed' => $package['from'] === null ? null : mb_substr($package['from'], 0, 128),
                'candidate' => mb_substr($package['to'], 0, 128),
                'security' => $package['security'],
            ];
        }

        return [$rows, false];
    }

    /**
     * @param  array<mixed>  $list
     * @return array<string, string>
     */
    private static function versions(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['version'] ?? null)) {
                $out[$entry['name']] = $entry['version'];
            }
        }

        return $out;
    }

    /** @return array{checkedAt: string|null, complete: bool, rebootRequired: bool, restarts: array<string, mixed>|null, held: list<string>, os: string|null, packages: list<array{name: string, from: string|null, to: string, security: bool, arch: string|null}>} */
    public static function snapshot(Machine $machine): array
    {
        return [
            'checkedAt' => $machine->updates_checked_at?->toIso8601String(),
            'complete' => $machine->updates_complete,
            'rebootRequired' => $machine->needsReboot(),
            'restarts' => $machine->restarts,
            'held' => $machine->held_packages ?? [],
            'os' => self::osName($machine),
            'packages' => array_values(MachinePackageUpdate::query()->where('machine_id', $machine->id)
                ->orderByDesc('security')->orderBy('name')->get()
                ->map(fn (MachinePackageUpdate $p) => ['name' => $p->name, 'from' => $p->installed, 'to' => $p->candidate, 'security' => $p->security, 'arch' => $p->arch])
                ->all()),
        ];
    }

    public static function osName(Machine $machine): ?string
    {
        $release = $machine->os_release ?? [];
        if (is_string($release['pretty_name'] ?? null)) {
            return $release['pretty_name'];
        }
        $facts = $machine->facts ?? [];
        $name = trim(($facts['os_name'] ?? $facts['os'] ?? '').' '.($facts['os_version'] ?? ''));

        return $name === '' ? null : $name;
    }

    public static function releaseKey(Machine $machine): string
    {
        $release = $machine->os_release ?? [];
        if (is_string($release['id'] ?? null)) {
            return trim($release['id'].' '.($release['version_id'] ?? ''));
        }

        return self::osName($machine) ?? 'unknown';
    }

    public static function installing(Machine $machine): ?DaemonJob
    {
        return self::active($machine)->first(fn (DaemonJob $job) => in_array($job->kind, self::INSTALL_KINDS, true)
            || ($job->kind === 'package_updates' && (bool) ($job->command['job']['apply'] ?? false)));
    }

    public static function checking(Machine $machine): ?DaemonJob
    {
        return self::active($machine)->first(fn (DaemonJob $job) => $job->kind === 'package_updates' && ! (bool) ($job->command['job']['apply'] ?? false));
    }

    /** @return Collection<int, DaemonJob> */
    private static function active(Machine $machine): Collection
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->whereIn('kind', self::KINDS)
            ->whereIn('state', ['queued', 'running'])
            ->latest()
            ->get()
            ->reject(fn (DaemonJob $job) => $job->isStale())
            ->values();
    }

    public static function check(Machine $machine, ?User $as = null): ?DaemonJob
    {
        $queue = app(DaemonJobQueue::class);
        $user = $as ?? $machine->workspace->owner;
        if ($user === null || $queue->unavailable($machine) !== null) {
            return null;
        }
        if (self::active($machine)->contains(fn (DaemonJob $job) => $job->kind !== 'package_hold')) {
            return null;
        }

        try {
            return $queue->queue($machine, $user, 'package_updates', ['type' => 'package_updates', 'apply' => false, 'security_only' => false], null, self::CHECK_TIMEOUT);
        } catch (RuntimeException $e) {
            report($e);

            return null;
        }
    }
}
