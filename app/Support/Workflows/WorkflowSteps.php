<?php

namespace App\Support\Workflows;

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Projects\Environments;

/**
 * What a workflow can be made of: the trigger, the steps, the ports each
 * one leaves by, and the settings each takes. The editor draws from the
 * same list (resources/js/components/workflows/workflowKinds.ts).
 */
final class WorkflowSteps
{
    /** What can start a workflow. */
    public const EVENTS = [
        'manual', 'schedule', 'webhook', 'git.push',
        'deploy.finished', 'service.down', 'service.crash_loop',
        'monitor.down', 'monitor.up',
        'machine.offline', 'machine.disk_full', 'backup.failed',
    ];

    /** Kind => the ports it leaves by. */
    public const PORTS = [
        'trigger' => ['out'],
        'redeploy' => ['out', 'failed'],
        'release' => ['out', 'failed'],
        'restart' => ['out', 'failed'],
        'start' => ['out', 'failed'],
        'stop' => ['out', 'failed'],
        'command' => ['out', 'failed'],
        'agent' => ['out', 'failed'],
        'backup' => ['out', 'failed'],
        'copy_data' => ['out', 'failed'],
        'promote' => ['out', 'failed'],
        'check_monitor' => ['out', 'failed'],
        'http' => ['out', 'failed'],
        'alert' => ['out'],
        'wait' => ['out'],
        'branch' => ['true', 'false'],
    ];

    public const CONDITIONS = ['monitor_up', 'service_running', 'disk_below', 'time_between', 'trigger_field'];

    /** "Whatever the trigger was about": a machine, a service. */
    public const FROM_TRIGGER = '@trigger';

    /** Every machine in the workspace (backups only). */
    public const ALL_MACHINES = '@all';

    /** Who an alert goes to. */
    public const ALERT_TO = ['team', 'emails', 'both'];

    /** Addresses an alert may email besides the team. */
    public const MAX_EMAILS = 5;

    public const MAX_NODES = 60;

    public const MAX_EDGES = 120;

    /**
     * The graph with anything malformed dropped, ready to store.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function clean(mixed $graph): array
    {
        $nodes = [];
        foreach (array_slice(is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [], 0, self::MAX_NODES) as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! isset(self::PORTS[$node['kind'] ?? ''])) {
                continue;
            }
            $id = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $node['id']) ?? '', 0, 64);
            if ($id === '' || isset($nodes[$id])) {
                continue;
            }
            $rules = [];
            foreach (is_array($node['rules'] ?? null) ? $node['rules'] : [] as $key => $value) {
                if (is_string($key) && preg_match('/^[a-z_]{1,32}$/', $key) === 1 && (is_scalar($value) || $value === null)) {
                    $rules[$key] = is_string($value) ? mb_substr($value, 0, match ($key) {
                        'steps' => 6000,
                        'prompt' => AgentSpec::MAX_PROMPT,
                        'body', 'command', 'message', 'setup', 'check' => 2000,
                        default => 300,
                    }) : $value;
                }
            }
            $nodes[$id] = [
                'id' => $id,
                'kind' => $node['kind'],
                'x' => (int) round(max(-20000, min(20000, (float) ($node['x'] ?? 0)))),
                'y' => (int) round(max(-20000, min(20000, (float) ($node['y'] ?? 0)))),
                'title' => mb_substr(trim((string) ($node['title'] ?? '')), 0, 80),
                'description' => mb_substr(trim((string) ($node['description'] ?? '')), 0, 280),
                'rules' => (object) $rules,
            ];
        }

        // One trigger: the first one wins.
        $seenTrigger = false;
        foreach ($nodes as $id => $node) {
            if ($node['kind'] === 'trigger') {
                if ($seenTrigger) {
                    unset($nodes[$id]);
                }
                $seenTrigger = true;
            }
        }

        $edges = [];
        foreach (array_slice(is_array($graph['edges'] ?? null) ? $graph['edges'] : [], 0, self::MAX_EDGES) as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = $nodes[$edge['source'] ?? ''] ?? null;
            $target = $nodes[$edge['target'] ?? ''] ?? null;
            $port = $edge['port'] ?? 'out';
            if ($source === null || $target === null || $source['id'] === $target['id'] || $target['kind'] === 'trigger'
                || ! in_array($port, self::PORTS[$source['kind']], true)) {
                continue;
            }
            $key = "{$source['id']}:{$port}:{$target['id']}";
            $edges[$key] = [
                'id' => substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($edge['id'] ?? '')) ?: md5($key), 0, 64),
                'source' => $source['id'],
                'port' => $port,
                'target' => $target['id'],
            ];
        }

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    /**
     * What stops a workflow from being turned on, per step, in plain words.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array<string, string> node id (or "" for the whole workflow) => problem
     */
    public static function problems(array $graph, Workspace $workspace): array
    {
        $problems = [];
        $machines = $workspace->machines()->get()->keyBy('id');
        $monitors = $workspace->monitors()->pluck('id')->all();
        $projects = $workspace->projects()->with('environments')->get()->keyBy('id');
        $trigger = collect($graph['nodes'])->firstWhere('kind', 'trigger');
        $event = $trigger ? (string) (((array) $trigger['rules'])['event'] ?? 'manual') : null;

        if ($trigger === null) {
            $problems[''] = 'Add a trigger: what starts the workflow.';
        } elseif (! in_array($event, self::EVENTS, true)) {
            $problems[$trigger['id']] = 'Choose what starts the workflow.';
        }
        if (count($graph['nodes']) < 2) {
            $problems[''] ??= 'Add at least one step after the trigger.';
        }

        $machineFor = function (array $rules, string $kind) use ($machines, $event): Machine|string|null {
            $id = $rules['machine_id'] ?? null;
            if ($id === self::ALL_MACHINES) {
                return $kind === 'backup' && $machines->isNotEmpty() ? self::ALL_MACHINES : null;
            }
            if ($id === self::FROM_TRIGGER) {
                return in_array($event, ['deploy.finished', 'service.down', 'service.crash_loop', 'machine.offline', 'machine.disk_full', 'backup.failed'], true)
                    ? self::FROM_TRIGGER
                    : null;
            }

            return $machines[(int) $id] ?? null;
        };

        foreach ($graph['nodes'] as $node) {
            $rules = (array) $node['rules'];
            $problem = match ($node['kind']) {
                'trigger' => match ($event) {
                    'schedule' => in_array($rules['every'] ?? null, ['hour', 'day', 'week'], true) ? null : 'Choose how often it runs.',
                    'monitor.down', 'monitor.up' => ($rules['monitor_id'] ?? '') === '' || in_array((int) $rules['monitor_id'], $monitors, true) ? null : 'That monitor is gone; choose another.',
                    'git.push' => match (true) {
                        ReleaseSpec::github((string) ($rules['repo'] ?? '')) === null => 'Enter the repository, as owner/name.',
                        ! ReleaseSpec::validBranch((string) (($rules['branch'] ?? '') ?: 'main')) => 'That isn’t a branch name.',
                        default => null,
                    },
                    default => null,
                },
                'redeploy', 'restart', 'start', 'stop', 'command', 'agent', 'backup' => match (true) {
                    $machineFor($rules, $node['kind']) === null => match ($rules['machine_id'] ?? null) {
                        self::FROM_TRIGGER => 'This trigger isn’t about a machine; choose one.',
                        self::ALL_MACHINES => $machines->isEmpty() ? 'Connect a machine first.' : 'Choose a machine.',
                        default => $machines->isEmpty() ? 'Connect a machine first.' : 'Choose a machine.',
                    },
                    $node['kind'] === 'redeploy' && ($rules['service'] ?? '') === '' => 'Choose the app to redeploy. Deploy it once from its page first if it isn’t listed.',
                    $node['kind'] === 'agent' && ($rules['service'] ?? '') === '' => 'Deploy the Coding agent template to a machine, then choose its container.',
                    $node['kind'] !== 'backup' && ($rules['service'] ?? '') === '' => 'Choose a service.',
                    $node['kind'] !== 'backup' && $rules['service'] === self::FROM_TRIGGER && ! in_array($event, $node['kind'] === 'redeploy' ? ['deploy.finished'] : ['service.down', 'service.crash_loop'], true) => 'This trigger isn’t about a service; choose one.',
                    $node['kind'] === 'command' && trim((string) ($rules['command'] ?? '')) === '' => 'Write the command to run.',
                    $node['kind'] === 'agent' && ! str_starts_with((string) $rules['service'], 'docker:') => 'Choose the agent’s container, like one from the Coding agent template.',
                    $node['kind'] === 'agent' => AgentSpec::problem($rules, $event),
                    default => null,
                },
                'release' => self::releaseProblem($rules, $machines->get((int) ($rules['machine_id'] ?? 0))),
                'copy_data' => (function () use ($projects, $rules) {
                    $project = $projects->get((int) ($rules['project'] ?? 0));
                    $environment = $project?->environments->firstWhere('id', (int) ($rules['environment'] ?? 0));

                    return match (true) {
                        $projects->isEmpty() => 'Make a project first, from Projects.',
                        $project === null => 'Choose a project.',
                        $environment === null => 'Choose the environment to copy data into.',
                        $environment->source_id === null => 'Choose a copy, like Staging: data comes from the environment it was made from.',
                        default => null,
                    };
                })(),
                'promote' => (function () use ($projects, $rules) {
                    $project = $projects->get((int) ($rules['project'] ?? 0));
                    $from = $project?->environments->firstWhere('id', (int) ($rules['from'] ?? 0));
                    $to = $project?->environments->firstWhere('id', (int) ($rules['to'] ?? 0));

                    return match (true) {
                        $projects->isEmpty() => 'Make a project first, from Projects.',
                        $project === null => 'Choose a project.',
                        $from === null => 'Choose the environment whose code goes out.',
                        $to === null => 'Choose the environment it goes to.',
                        $from->id === $to->id => 'Choose two different environments.',
                        ! Environments::related($from, $to) => "{$from->name} wasn’t copied from {$to->name}, or the other way round. Choose an environment and the one it was copied from.",
                        default => null,
                    };
                })(),
                'check_monitor' => in_array((int) ($rules['monitor_id'] ?? 0), $monitors, true) ? null : 'Choose a monitor.',
                'http' => filter_var($rules['url'] ?? '', FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $rules['url']) ? null : 'Enter the URL to call.',
                'alert' => match (true) {
                    trim((string) ($rules['subject'] ?? '')) === '' => 'Write what the alert says.',
                    ($rules['to'] ?? 'team') !== 'team' && self::emails($rules['emails'] ?? null) === [] => 'Add who to email.',
                    ($rules['to'] ?? 'team') !== 'team' && self::emails($rules['emails'] ?? null) === null => 'Up to '.self::MAX_EMAILS.' email addresses, separated by commas.',
                    default => null,
                },
                'wait' => (int) ($rules['minutes'] ?? 0) >= 1 && (int) $rules['minutes'] <= 1440 ? null : 'Wait between 1 minute and a day.',
                'branch' => in_array($rules['condition'] ?? null, self::CONDITIONS, true) ? null : 'Choose what to check.',
                default => null,
            };
            if ($problem !== null) {
                $problems[$node['id']] = $problem;
            }
        }

        return $problems;
    }

    /**
     * What's missing from a "Release app" step.
     *
     * @param  array<string, mixed>  $rules
     */
    private static function releaseProblem(array $rules, ?Machine $machine): ?string
    {
        $services = $machine?->inventory['services'] ?? null;
        $managed = function (string $key) use ($services): bool {
            foreach (is_array($services) ? $services : [] as $s) {
                if (is_array($s) && ($s['key'] ?? null) === $key) {
                    return ($s['details']['managed'] ?? null) === 'true';
                }
            }

            return false;
        };

        return match (true) {
            $machine === null => 'Choose the machine the app runs on.',
            ($rules['service'] ?? '') === '' => 'Choose the service the app runs under, like PHP-FPM.',
            ! $managed((string) $rules['service']) => 'That service isn’t managed by ServerOS; adopt it first.',
            collect([...ReleaseSpec::list($rules['reload'] ?? ''), ...ReleaseSpec::list($rules['restart'] ?? '')])->contains(fn (string $key) => ! $managed($key)) => 'A service to reload or restart isn’t managed by ServerOS; adopt it first.',
            ! in_array($rules['mode'] ?? 'in_place', ReleaseSpec::MODES, true) => 'Choose how releases go live.',
            ReleaseSpec::atomic($rules) && collect([...ReleaseSpec::list($rules['shared'] ?? ''), ...ReleaseSpec::list($rules['trim'] ?? '')])->contains(fn (string $path) => ! ReleaseSpec::validInside($path)) => 'Shared and trimmed paths are inside the app, like storage or .env.',
            ! ReleaseSpec::validPath((string) ($rules['path'] ?? '')) => 'Enter the app’s folder, like /var/www/app.',
            ! ReleaseSpec::validUser((string) (($rules['user'] ?? '') ?: ReleaseSpec::DEFAULT_USER)) => 'Enter the user the app runs as. Never root.',
            ReleaseSpec::github((string) ($rules['repo'] ?? '')) === null => 'Enter the repository, as owner/name.',
            ! ReleaseSpec::validBranch((string) (($rules['branch'] ?? '') ?: 'main')) => 'That isn’t a branch name.',
            ReleaseSpec::steps($rules['steps'] ?? null) === null => 'Up to '.ReleaseSpec::MAX_STEPS.' steps.',
            ($rules['health_url'] ?? '') !== '' && ! preg_match('#^https://[^\s]+$#i', (string) $rules['health_url']) => 'The health check should be an https:// address.',
            default => null,
        };
    }

    /**
     * The addresses an alert emails besides the team: a list, or null if
     * any is invalid or there are too many.
     *
     * @return list<string>|null
     */
    public static function emails(mixed $value): ?array
    {
        $emails = array_values(array_unique(array_filter(array_map(
            fn (string $e) => strtolower(trim($e)),
            preg_split('/[,;\s]+/', (string) $value) ?: [],
        ))));
        if (count($emails) > self::MAX_EMAILS) {
            return null;
        }
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
        }

        return $emails;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    public static function hasCommand(array $graph): bool
    {
        return collect($graph['nodes'])->contains(fn (array $n) => in_array($n['kind'], ['command', 'release', 'agent'], true));
    }
}
