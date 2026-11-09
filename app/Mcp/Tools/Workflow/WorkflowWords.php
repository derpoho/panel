<?php

namespace App\Mcp\Tools\Workflow;

use App\Models\Workspace;
use App\Support\Workflows\WorkflowInputs;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowText;
use Illuminate\Support\Str;

/**
 * A workflow's trigger and steps in plain words, the way easy mode and
 * the Workflows list say them ("Restart shop on web-1", "Every day at
 * 03:00 UTC"), with machines, services and monitors named from the
 * workspace's own choices.
 *
 * @phpstan-type MachineChoice array{id: int, name: string, services: list<array{key: string, name: string, managed: bool, container: bool}>, deploys: list<string>}
 */
final class WorkflowWords
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @param  array{machines: list<MachineChoice>, monitors: list<array{id: int, name: string}>, projects?: list<array{id: int, name: string, environments: list<array{id: int, name: string, source: int|null}>}>}  $options  from WorkflowInputs::options()
     */
    public function __construct(public readonly array $options) {}

    public static function for(Workspace $workspace): self
    {
        return new self(WorkflowInputs::options($workspace));
    }

    /**
     * What starts it, with its filters.
     *
     * @param  array<string, mixed>  $rules  the trigger's rules
     */
    public function trigger(array $rules): string
    {
        $event = (string) ($rules['event'] ?? 'manual');
        $label = WorkflowReference::label($event);
        $filled = fn (string $key) => isset($rules[$key]) && $rules[$key] !== '';
        $machine = $filled('machine_id') ? $this->machineName($rules['machine_id']) : null;

        return match ($event) {
            'schedule' => match ($rules['every'] ?? null) {
                'hour' => 'Every hour at :'.substr((string) ($rules['at'] ?? '00:00'), 3, 2),
                'day' => 'Every day at '.($rules['at'] ?? '03:00').' UTC',
                'week' => 'Every week on '.(self::WEEKDAYS[(int) ($rules['weekday'] ?? 1)] ?? 'Monday').' at '.($rules['at'] ?? '03:00').' UTC',
                default => 'On a schedule (not set yet)',
            },
            'git.push' => $label.': '.($filled('branch') ? $rules['branch'] : 'main').' of '.($filled('repo') ? $rules['repo'] : 'a repository'),
            'monitor.down', 'monitor.up' => $label.': '.($filled('monitor_id') ? $this->monitorName($rules['monitor_id']) : 'any monitor'),
            'deploy.finished' => $label.': '.($filled('service') ? (string) $rules['service'] : 'any app').' on '.($machine ?? 'any machine').', when it '.match ($rules['outcome'] ?? 'any') {
                'succeeded' => 'succeeds',
                'failed' => 'fails',
                default => 'succeeds or fails',
            },
            'service.down', 'service.crash_loop' => $label.': '.($filled('service') ? $this->serviceName($rules['service'], $rules['machine_id'] ?? null) : 'any service').' on '.($machine ?? 'any machine'),
            'machine.offline', 'machine.disk_full', 'backup.failed' => $label.': '.($machine ?? 'any machine'),
            default => $label,
        };
    }

    /**
     * One step in a phrase, from its settings.
     *
     * @param  array<string, mixed>  $node
     */
    public function step(array $node): string
    {
        $r = (array) ($node['rules'] ?? []);
        $service = $this->serviceName($r['service'] ?? null, $r['machine_id'] ?? null);
        $machine = $this->machineName($r['machine_id'] ?? null);

        return match ($node['kind'] ?? null) {
            'trigger' => $this->trigger($r),
            'redeploy' => 'Deploy the latest '.match (true) {
                ($r['service'] ?? null) === WorkflowSteps::FROM_TRIGGER => 'code of the app from the trigger',
                filled($r['service'] ?? null) => (string) $r['service'],
                default => 'code',
            }." on {$machine}",
            'release' => 'Release '.(filled($r['repo'] ?? null) ? $r['repo'] : 'an app').(filled($r['path'] ?? null) ? " to {$r['path']}" : '')." on {$machine}",
            'restart', 'start', 'stop' => str_replace(' service', '', WorkflowText::kindLabel($node['kind']))." {$service} on {$machine}",
            'command' => 'Run “'.(filled($r['command'] ?? null) ? $r['command'] : '…')."” in {$service} on {$machine}",
            'agent' => (($r['agent'] ?? 'claude') === 'codex' ? 'Codex' : 'Claude Code').' in '.$service.' on '.$machine.': “'.(filled($r['prompt'] ?? null) ? Str::limit((string) $r['prompt'], 80, '…') : '…').'”'.(filled($r['repo'] ?? null) ? ' in '.$r['repo'].(($r['pull_request'] ?? false) ? ', as a pull request' : '') : ''),
            'backup' => "Back up {$machine}",
            'copy_data' => 'Copy data into '.$this->environmentName($r['project'] ?? null, $r['environment'] ?? null).' of '.$this->projectName($r['project'] ?? null),
            'promote' => 'Promote '.$this->environmentName($r['project'] ?? null, $r['from'] ?? null).' to '.$this->environmentName($r['project'] ?? null, $r['to'] ?? null).' in '.$this->projectName($r['project'] ?? null),
            'check_monitor' => 'Check '.$this->monitorName($r['monitor_id'] ?? null).' is up, for up to '.($r['timeout'] ?? 5).' min',
            'http' => ($r['method'] ?? 'POST').' '.(filled($r['url'] ?? null) ? Str::limit((string) preg_replace('#^https?://#i', '', (string) $r['url']), 48, '…') : 'a URL'),
            'alert' => 'Email '.match ($r['to'] ?? 'team') {
                'emails' => filled($r['emails'] ?? null) ? (string) $r['emails'] : 'someone',
                'both' => 'the team and '.(filled($r['emails'] ?? null) ? (string) $r['emails'] : 'someone'),
                default => 'the team',
            }.': “'.(filled($r['subject'] ?? null) ? $r['subject'] : '…').'”',
            'wait' => 'Wait '.($r['minutes'] ?? 1).' '.((int) ($r['minutes'] ?? 1) === 1 ? 'minute' : 'minutes'),
            'branch' => match ($r['condition'] ?? null) {
                'monitor_up' => 'Is '.$this->monitorName($r['monitor_id'] ?? null).' up?',
                'service_running' => "Is {$service} running on {$machine}?",
                'disk_below' => "Is {$machine}’s disk under ".($r['percent'] ?? 85).'%?',
                'time_between' => 'Is it between '.($r['from'] ?? '09:00').' and '.($r['to'] ?? '17:00').' UTC?',
                'trigger_field' => filled($r['field'] ?? null) ? "Is {$r['field']} “".($r['value'] ?? '').'”?' : 'Choose what to check',
                default => 'Choose what to check',
            },
            default => (string) ($node['kind'] ?? 'step'),
        };
    }

    public function machineName(mixed $id): string
    {
        return match (true) {
            $id === WorkflowSteps::FROM_TRIGGER => 'the machine from the trigger',
            $id === WorkflowSteps::ALL_MACHINES => 'every machine',
            default => $this->machine($id)['name'] ?? 'a machine',
        };
    }

    public function serviceName(mixed $key, mixed $machineId): string
    {
        if ($key === WorkflowSteps::FROM_TRIGGER) {
            return 'the app from the trigger';
        }
        if (! filled($key)) {
            return 'an app';
        }
        foreach ($this->machine($machineId)['services'] ?? [] as $service) {
            if ($service['key'] === $key || $service['name'] === $key) {
                return $service['name'];
            }
        }

        return (string) $key;
    }

    public function projectName(mixed $id): string
    {
        foreach ($this->options['projects'] ?? [] as $project) {
            if (filled($id) && (string) $project['id'] === (string) $id) {
                return $project['name'];
            }
        }

        return 'a project';
    }

    public function environmentName(mixed $project, mixed $id): string
    {
        foreach ($this->options['projects'] ?? [] as $p) {
            if (filled($project) && (string) $p['id'] === (string) $project) {
                foreach ($p['environments'] as $environment) {
                    if (filled($id) && (string) $environment['id'] === (string) $id) {
                        return $environment['name'];
                    }
                }
            }
        }

        return 'an environment';
    }

    public function monitorName(mixed $id): string
    {
        foreach ($this->options['monitors'] as $monitor) {
            if (filled($id) && (string) $monitor['id'] === (string) $id) {
                return $monitor['name'];
            }
        }

        return 'a monitor';
    }

    /**
     * @return MachineChoice|null
     */
    public function machine(mixed $id): ?array
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }
        foreach ($this->options['machines'] as $machine) {
            if ((string) $machine['id'] === (string) $id) {
                return $machine;
            }
        }

        return null;
    }

    /** @return list<int> */
    public function monitorIds(): array
    {
        return array_map(fn ($m) => (int) $m['id'], $this->options['monitors']);
    }
}
