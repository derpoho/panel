<?php

namespace App\Support;

use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Support\Machines\DaemonUpdateNotices;
use App\Support\Machines\MachinePolicies;
use RuntimeException;

/**
 * Releases reach machines when they're published, not only when each
 * daemon next checks (every few hours): every connected machine on an
 * older version is offered the newest stable release for its architecture.
 * The daemon still decides, as it does for its own checks: automatic
 * updates on or off, no major version without approval, and only once its
 * running jobs are done. Machines whose policy waits for approval aren't
 * offered it here: install() sends one when someone approves it.
 */
class DaemonUpdates
{
    public function __construct(private readonly DaemonGatewayClient $gateway, private readonly DaemonUpdateNotices $notices) {}

    /**
     * @return array{offered: list<string>, failed: array<string, string>, waiting: list<string>} machine names, why it couldn't reach some, and those whose policy waits for approval
     */
    public function push(?string $arch = null): array
    {
        [$offered, $failed] = [[], []];
        /** @var array<int, array{release: DaemonRelease, machines: list<Machine>}> $waiting by workspace */
        $waiting = [];

        foreach (Machine::query()->whereNotNull('uid')->whereNull('disconnected_at')->get() as $machine) {
            $machineArch = DaemonRelease::arch((string) ($machine->facts['arch'] ?? ''));
            if (! $machine->isOnline() || $machineArch === null || ($arch !== null && $machineArch !== $arch)) {
                continue;
            }
            // Its policy waits for someone to approve each update (the
            // machine's settings page offers it), or stays on one version.
            $updates = MachinePolicies::for($machine)->updates;
            if ($updates !== 'automatic') {
                if ($updates === 'approve' && ($release = DaemonRelease::latest('stable', $machineArch)) !== null && self::newer($release->version, $machine->daemon_version)) {
                    $waiting[$machine->workspace_id] ??= ['release' => $release, 'machines' => []];
                    $waiting[$machine->workspace_id]['machines'][] = $machine;
                }

                continue;
            }
            $release = DaemonRelease::latest('stable', $machineArch);
            if ($release === null || ! self::newer($release->version, $machine->daemon_version)) {
                continue;
            }

            $candidate = $release->toCandidate();
            try {
                $this->gateway->control($machine, [
                    'type' => 'self_update',
                    'version' => $candidate['version'],
                    'url' => $candidate['url'],
                    'sha256' => $candidate['sha256'],
                    'signature' => $candidate['signature'],
                    'channel' => $candidate['channel'],
                    'min_from' => $candidate['min_from'],
                    'approved' => false,
                ]);
                $offered[] = $machine->name;
            } catch (RuntimeException $e) {
                $failed[$machine->name] = $e->getMessage();
            }
        }

        // One note per workspace, not one per machine.
        foreach ($waiting as $wait) {
            $this->notices->waiting($wait['machines'][0]->workspace, $wait['release'], $wait['machines']);
        }

        return [
            'offered' => $offered,
            'failed' => $failed,
            'waiting' => array_merge(...array_map(fn (array $wait) => array_map(fn (Machine $machine) => $machine->name, $wait['machines']), array_values($waiting))),
        ];
    }

    /**
     * The newest stable release for the machine's architecture, when it's
     * newer than what the machine runs.
     */
    public static function available(Machine $machine): ?DaemonRelease
    {
        $arch = DaemonRelease::arch((string) ($machine->facts['arch'] ?? ''));
        if ($arch === null) {
            return null;
        }

        // Pinned: the pinned release, when it isn't what it runs (older or newer).
        $policy = MachinePolicies::for($machine);
        if ($policy->updates === 'pinned' && $policy->pinned_version !== null) {
            $pinned = DaemonRelease::query()->where('version', $policy->pinned_version)->where('arch', $arch)->first();

            return $pinned !== null && ltrim($pinned->version, 'v') !== ltrim((string) $machine->daemon_version, 'v') ? $pinned : null;
        }

        $release = DaemonRelease::latest('stable', $arch);

        return $release !== null && self::newer($release->version, $machine->daemon_version) ? $release : null;
    }

    /**
     * Install the newest release on this machine now, approved by someone:
     * it goes past "automatic updates off" (a pinned version still holds).
     *
     * @throws RuntimeException when there's nothing newer or it's offline
     */
    public function install(Machine $machine): DaemonRelease
    {
        $release = self::available($machine) ?? throw new RuntimeException('This machine already runs the newest release.');
        $candidate = $release->toCandidate();
        $this->gateway->control($machine, [
            'type' => 'self_update',
            'version' => $candidate['version'],
            'url' => $candidate['url'],
            'sha256' => $candidate['sha256'],
            'signature' => $candidate['signature'],
            'channel' => $candidate['channel'],
            'min_from' => $candidate['min_from'],
            'approved' => true,
        ]);

        return $release;
    }

    /** Whether a release is newer than what a machine runs (v0.1.1-pre.4 over v0.1.1-pre.2). */
    public static function newer(string $release, ?string $running): bool
    {
        return $running === null || $running === '' || self::compare($release, $running) > 0;
    }

    /**
     * Semver order: numbers first, then a release beats its prereleases
     * (v0.1.1 over v0.1.1-pre.4), and prereleases go part by part.
     */
    private static function compare(string $a, string $b): int
    {
        [$coreA, $preA] = array_pad(explode('-', ltrim($a, 'v'), 2), 2, null);
        [$coreB, $preB] = array_pad(explode('-', ltrim($b, 'v'), 2), 2, null);

        $partsA = array_map('intval', explode('.', (string) $coreA));
        $partsB = array_map('intval', explode('.', (string) $coreB));
        for ($i = 0; $i < max(count($partsA), count($partsB)); $i++) {
            if (($partsA[$i] ?? 0) !== ($partsB[$i] ?? 0)) {
                return ($partsA[$i] ?? 0) <=> ($partsB[$i] ?? 0);
            }
        }

        if ($preA === null || $preB === null) {
            return ($preA === null) <=> ($preB === null);
        }
        $idsA = explode('.', $preA);
        $idsB = explode('.', $preB);
        for ($i = 0; $i < max(count($idsA), count($idsB)); $i++) {
            if (! isset($idsA[$i]) || ! isset($idsB[$i])) {
                return isset($idsA[$i]) <=> isset($idsB[$i]);
            }
            $order = ctype_digit($idsA[$i]) && ctype_digit($idsB[$i]) ? (int) $idsA[$i] <=> (int) $idsB[$i] : strcmp($idsA[$i], $idsB[$i]);
            if ($order !== 0) {
                return $order <=> 0;
            }
        }

        return 0;
    }
}
