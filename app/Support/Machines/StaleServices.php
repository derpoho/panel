<?php

namespace App\Support\Machines;

use App\Models\DaemonJob;
use App\Models\Machine;

/**
 * Services that still run files an update replaced. Restarting them applies
 * the update without a reboot; only a newer kernel or microcode needs one.
 * The daemon finds them (`restarts` on every package report) and restarts
 * them with a restart_stale job.
 */
final class StaleServices
{
    public const string KIND = 'restart_stale';

    public const int TIMEOUT = 900;

    public const string UNIT_PATTERN = '/^[A-Za-z0-9@._:\\\\-]{1,248}\.service$/';

    /**
     * The daemon's `restarts`, checked and trimmed for keeping, or null when
     * it didn't send any (a daemon from before this).
     *
     * @param  array<string, mixed>  $result
     * @return array{services: list<array{unit: string, processes: int, files: list<string>, disruptive: bool}>, systemd: bool, reboot: list<array{kind: string, detail: string}>, later: list<string>, kernel: string|null, newestKernel: string|null}|null
     */
    public static function fromResult(array $result): ?array
    {
        $restarts = $result['restarts'] ?? null;
        if (! is_array($restarts) || ! is_array($restarts['services'] ?? null) || ! is_array($restarts['reboot'] ?? null)) {
            return null;
        }

        $services = [];
        foreach (array_slice($restarts['services'], 0, 200) as $service) {
            if (! is_array($service) || ! is_string($service['unit'] ?? null) || preg_match(self::UNIT_PATTERN, $service['unit']) !== 1) {
                continue;
            }
            $services[] = [
                'unit' => $service['unit'],
                'processes' => max(1, (int) ($service['processes'] ?? 1)),
                'files' => array_values(array_map(fn (string $f) => mb_substr($f, 0, 300), array_slice(array_filter(is_array($service['files'] ?? null) ? $service['files'] : [], 'is_string'), 0, 5))),
                'disruptive' => (bool) ($service['disruptive'] ?? false),
            ];
        }

        $reboot = [];
        foreach (array_slice($restarts['reboot'], 0, 20) as $reason) {
            if (is_array($reason) && is_string($reason['kind'] ?? null) && is_string($reason['detail'] ?? null)) {
                $reboot[] = ['kind' => mb_substr($reason['kind'], 0, 40), 'detail' => mb_substr($reason['detail'], 0, 200)];
            }
        }

        $text = fn (mixed $value) => is_string($value) && $value !== '' ? mb_substr($value, 0, 120) : null;

        return [
            'services' => $services,
            'systemd' => (bool) ($restarts['systemd'] ?? false),
            'reboot' => $reboot,
            'later' => array_values(array_filter(array_slice(is_array($restarts['later'] ?? null) ? $restarts['later'] : [], 0, 50), fn ($u) => is_string($u) && preg_match(self::UNIT_PATTERN, $u) === 1)),
            'kernel' => $text($restarts['kernel'] ?? null),
            'newestKernel' => $text($restarts['newest_kernel'] ?? null),
        ];
    }

    /**
     * The job that restarts what's stale. Services that stop everything
     * they run (Docker) only when named.
     *
     * @param  list<string>  $include
     * @return array{type: string, units: list<string>, systemd: bool}|null
     */
    public static function job(Machine $machine, array $include = []): ?array
    {
        $restarts = $machine->restarts;
        if ($restarts === null) {
            return null;
        }

        $units = array_values(array_map(
            fn (array $s) => $s['unit'],
            array_filter($restarts['services'], fn (array $s) => ! $s['disruptive'] || in_array($s['unit'], $include, true)),
        ));
        if ($units === [] && ! $restarts['systemd']) {
            return null;
        }

        return ['type' => self::KIND, 'units' => $units, 'systemd' => $restarts['systemd']];
    }

    /** How many the restart button would restart (systemd counts as one). */
    public static function count(Machine $machine): int
    {
        $job = self::job($machine);

        return $job === null ? 0 : count($job['units']) + ($job['systemd'] ? 1 : 0);
    }

    /** A finished restart: keep what the daemon found afterwards. */
    public static function ingest(DaemonJob $job): void
    {
        if ($job->kind !== self::KIND || $job->state !== 'succeeded' || ! is_array($job->result)) {
            return;
        }
        $restarts = self::fromResult($job->result);
        if ($restarts !== null) {
            $job->machine->forceFill(['restarts' => $restarts])->save();
        }
    }
}
