<?php

namespace App\Support;

use App\Models\DaemonJob;
use App\Models\Machine;

/**
 * The first look at a newly connected server, from what the daemon really
 * reported: which ports are open to the internet, waiting security updates,
 * services that won't survive a reboot, and the system itself.
 *
 * Every check is "scanning" until its data is in, then ok, warn or issue.
 */
class SecurityScan
{
    /**
     * @return array{finished: bool, checks: list<array{key: string, label: string, state: string, summary: string, items: list<array{text: string, tone: string}>}>}
     */
    public function report(Machine $machine, ?DaemonJob $updates): array
    {
        $checks = [
            $this->ports($machine),
            $this->updates($updates),
            $this->services($machine),
            $this->system($machine),
        ];

        return [
            'finished' => collect($checks)->every(fn (array $c) => $c['state'] !== 'scanning'),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, summary: string, items: list<array{text: string, tone: string}>}
     */
    private function ports(Machine $machine): array
    {
        if ($machine->inventory === null) {
            return $this->scanning('ports', 'Open ports');
        }

        $public = [];
        $closed = 0;
        foreach (Firewall::view($machine)['ports'] ?? [] as $port) {
            if (in_array($port['exposure'], Firewall::REACHABLE, true)) {
                $public[$port['port'].'/'.$port['proto']] = $port;
            } else {
                $closed++;
            }
        }
        $local = [];
        $internal = Firewall::bridgeAddresses($machine);
        foreach ($machine->inventory['listeners'] ?? [] as $listener) {
            if (! Firewall::isPublic((string) ($listener['address'] ?? ''), $internal)) {
                $local[((int) ($listener['port'] ?? 0)).'/'.rtrim((string) ($listener['proto'] ?? 'tcp'), '6')] = true;
            }
        }
        // Bound both locally and publicly counts once, as public.
        $local = array_diff_key($local, $public);

        $items = [];
        $risky = 0;
        foreach ($public as $key => $port) {
            $sensitive = $port['what'] !== null;
            $risky += $sensitive ? 1 : 0;

            $items[] = [
                'text' => $sensitive
                    ? "{$key} is open to the internet: {$port['what']} should usually only listen locally"
                    : "{$key} is open".($port['process'] !== null ? " ({$port['process']})" : ''),
                'tone' => $sensitive ? 'issue' : 'neutral',
            ];
        }

        $summary = count($public).' open to the internet · '.($closed > 0 ? "{$closed} closed by the firewall · " : '').count($local).' local only';

        return [
            'key' => 'ports',
            'label' => 'Open ports',
            'state' => $risky > 0 ? 'issue' : 'ok',
            'summary' => $summary,
            'items' => array_slice($items, 0, 6),
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, summary: string, items: list<array{text: string, tone: string}>}
     */
    private function updates(?DaemonJob $job): array
    {
        if ($job === null || $job->isActive()) {
            return $this->scanning('updates', 'Security updates');
        }

        if ($job->state !== 'succeeded') {
            return [
                'key' => 'updates',
                'label' => 'Security updates',
                'state' => 'unknown',
                'summary' => 'Couldn’t check for updates',
                'items' => [['text' => (string) ($job->error['message'] ?? 'The package manager didn’t answer.'), 'tone' => 'neutral']],
            ];
        }

        $security = array_values(array_filter($job->result['security'] ?? [], 'is_string'));
        $upgradable = count($job->result['upgradable'] ?? []);

        return [
            'key' => 'updates',
            'label' => 'Security updates',
            'state' => $security === [] ? 'ok' : 'warn',
            'summary' => $security === []
                ? ($upgradable === 0 ? 'Fully up to date' : "No security updates · {$upgradable} other")
                : count($security).' security update'.(count($security) === 1 ? '' : 's').' waiting',
            'items' => array_map(
                fn (string $line) => ['text' => strtok($line, ' ') ?: $line, 'tone' => 'warn'],
                array_slice($security, 0, 5),
            ),
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, summary: string, items: list<array{text: string, tone: string}>}
     */
    private function services(Machine $machine): array
    {
        if ($machine->inventory === null) {
            return $this->scanning('services', 'Services');
        }

        $services = $machine->inventory['services'] ?? [];
        $items = [];
        foreach ($services as $service) {
            if (($service['status'] ?? null) === 'failed') {
                $items[] = ['text' => "{$service['name']} has failed", 'tone' => 'issue'];
            } elseif (isset($service['details']['warning'])) {
                $items[] = ['text' => "{$service['name']}: {$service['details']['warning']}", 'tone' => 'warn'];
            }
        }

        $running = count(array_filter($services, fn (array $s) => ($s['status'] ?? null) === 'running'));
        $failed = collect($items)->contains('tone', 'issue');

        return [
            'key' => 'services',
            'label' => 'Services',
            'state' => $failed ? 'issue' : ($items === [] ? 'ok' : 'warn'),
            'summary' => count($services).' found · '.$running.' running',
            'items' => array_slice($items, 0, 5),
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, summary: string, items: list<array{text: string, tone: string}>}
     */
    private function system(Machine $machine): array
    {
        $facts = $machine->facts;
        if ($facts === null) {
            return $this->scanning('system', 'System');
        }

        $os = trim(($facts['os'] ?? '').' '.($facts['os_version'] ?? ''));
        $docker = $facts['docker_version'] ?? null;
        // The daemon doesn't always report a version; discovery still shows
        // whether Docker is there (its service, or any container).
        $hasDocker = $machine->hasDocker() === true;

        return [
            'key' => 'system',
            'label' => 'System',
            'state' => 'ok',
            'summary' => $os !== '' ? $os : 'Linux',
            'items' => array_values(array_filter([
                isset($facts['kernel']) ? ['text' => "Kernel {$facts['kernel']}", 'tone' => 'neutral'] : null,
                match (true) {
                    $docker !== null => ['text' => "Docker {$docker}", 'tone' => 'neutral'],
                    $hasDocker => ['text' => 'Docker is running', 'tone' => 'neutral'],
                    $machine->inventory === null => null,
                    default => ['text' => 'Docker isn’t installed, so deploys won’t run yet', 'tone' => 'warn'],
                },
                isset($facts['cpu_cores']) ? ['text' => "{$facts['cpu_cores']} cores · ".round(($facts['memory_bytes'] ?? 0) / 1024 ** 3, 1).' GB RAM', 'tone' => 'neutral'] : null,
            ])),
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, summary: string, items: list<array{text: string, tone: string}>}
     */
    private function scanning(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'state' => 'scanning', 'summary' => '', 'items' => []];
    }
}
