<?php

namespace App\Mcp\Tools\Service;

use App\Enums\WorkspacePermission;
use App\Http\Controllers\AppVersionController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Models\ServiceSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How services and their jobs look to an agent, built on Present: the
 * group a service is in, the facts the service page's Details tab shows,
 * its usage over a window, and what ServerOS deployed into it.
 */
final class ServiceView
{
    /** Chart windows, in seconds, as on the service page. */
    public const RANGES = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400];

    /** Points in a usage summary's trend. */
    private const TREND_POINTS = 12;

    /**
     * A service as list_services shows it.
     *
     * @param  array<string, mixed>  $service  from McpContext::describeService()
     * @return array<string, mixed>
     */
    public static function row(McpContext $context, Machine $machine, array $service, ?ServiceGroup $group): array
    {
        return [
            ...Present::service($machine, $service, $context),
            'group' => $group === null ? null : ['id' => $group->id, 'name' => $group->name],
            'hidden' => (bool) ($service['hidden'] ?? false),
        ];
    }

    /**
     * What the Details tab lists, the parts that are known.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    public static function details(array $service): array
    {
        $details = is_array($service['details'] ?? null) ? $service['details'] : [];

        return array_filter([
            'managedBy' => ($service['managed'] ?? false) ? (($service['origin'] ?? null) === 'created' ? 'created by ServerOS' : 'adopted') : 'discovered only',
            'composeProject' => $details['compose_project'] ?? null,
            'restartPolicy' => $details['restart_policy'] ?? null,
            'version' => $service['version'] ?? null,
            'runAs' => $service['user'] ?? null,
            'capabilities' => $service['capabilities'] ?? null,
            'workingDirectory' => $service['working_dir'] ?? null,
            'executable' => $service['exec'] ?? null,
            'configFiles' => $service['config_paths'] ?? null,
            'confidence' => $service['confidence'] ?? null,
            'warning' => $details['warning'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Usage over the window from the per-service readings: averages, peaks
     * and the latest, network as rates, and a short trend.
     *
     * @return array<string, mixed>
     */
    public static function usage(Machine $machine, string $key, string $range): array
    {
        $window = self::RANGES[$range];
        $rows = ServiceSample::query()
            ->where('machine_id', $machine->id)
            ->where('service', $key)
            ->where('ts', '>=', now()->getTimestamp() - $window)
            ->orderBy('ts')
            ->get(['ts', 'cpu', 'mem_used', 'net_rx', 'net_tx']);

        if ($rows->isEmpty()) {
            return ['range' => $range, 'samples' => 0];
        }

        $first = $rows->first();
        $last = $rows->last();
        $seconds = $last->ts - $first->ts;
        // Network counters are cumulative; one that went down was reset.
        $rate = fn (?int $from, ?int $to) => $seconds > 0 && $from !== null && $to !== null && $to >= $from ? round(($to - $from) / $seconds, 1) : null;
        $cpu = $rows->pluck('cpu')->filter(fn ($v) => $v !== null);
        $memory = $rows->pluck('mem_used')->filter(fn ($v) => $v !== null);

        $bucket = max(15, (int) ceil($window / self::TREND_POINTS));
        $trend = $rows->groupBy(fn (ServiceSample $s) => intdiv($s->ts, $bucket))->map(fn (Collection $group) => [
            'at' => Carbon::createFromTimestamp((int) $group->last()->ts)->toIso8601String(),
            'cpuPercent' => $group->avg('cpu') === null ? null : round((float) $group->avg('cpu'), 1),
            'memoryBytes' => $group->avg('mem_used') === null ? null : (int) $group->avg('mem_used'),
        ])->values()->all();

        return [
            'range' => $range,
            'samples' => $rows->count(),
            'from' => Carbon::createFromTimestamp((int) $first->ts)->toIso8601String(),
            'to' => Carbon::createFromTimestamp((int) $last->ts)->toIso8601String(),
            'cpuPercent' => $cpu->isEmpty() ? null : ['avg' => round((float) $cpu->avg(), 2), 'max' => round((float) $cpu->max(), 2), 'latest' => $last->cpu === null ? null : round((float) $last->cpu, 2)],
            'memoryBytes' => $memory->isEmpty() ? null : ['avg' => (int) $memory->avg(), 'max' => (int) $memory->max(), 'latest' => $last->mem_used],
            'networkBytesPerSecond' => ['in' => $rate($first->net_rx, $last->net_rx), 'out' => $rate($first->net_tx, $last->net_tx)],
            'trend' => $trend,
        ];
    }

    /**
     * What ServerOS deployed into the service from code, as the service
     * page's Code tile shows it, or null.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>|null
     */
    public static function app(Machine $machine, array $service): ?array
    {
        $deploy = AppVersionController::lastDeploy($machine, $service['name'] ?? null);
        if ($deploy === null) {
            return null;
        }
        $job = $deploy->command['job'] ?? [];
        $fromUpload = str_contains((string) ($job['repo'] ?? ''), '/api/deploy-sources/');

        return [
            'name' => $deploy->service,
            'commit' => $job['commit'] ?? null,
            'fromUpload' => $fromUpload,
            'repo' => $fromUpload ? null : ($job['repo'] ?? null),
            'domains' => array_values($job['domains'] ?? []),
            // The names of settings that mention the domain; never their values.
            'settingsMentioningDomain' => AppVersionController::mentions($deploy),
            'canMoveDomain' => DeploySource::fromRepoUrl($job['repo'] ?? null)?->usable() ?? false,
            'deployedAt' => $deploy->created_at?->toIso8601String(),
            'deployJobId' => $deploy->id,
        ];
    }

    /**
     * A job with what it was for: the action, and a console command's text
     * and output only for people who manage machines (as the activity page
     * shows them).
     *
     * @return array<string, mixed>
     */
    public static function job(McpContext $context, DaemonJob $job, int $logLines = 40): array
    {
        $out = Present::job($job, $logLines);
        $spec = $job->command['job'] ?? [];
        $private = $job->kind === 'service_exec' && ! $context->can(WorkspacePermission::ManageMachines);

        if ($private) {
            $out['log'] = [];
            $out['result'] = null;
            $out['note'] = 'Console commands and their output are shown only to people who manage machines.';
        }

        return [
            ...$out,
            'action' => is_string($spec['action'] ?? null) ? $spec['action'] : null,
            'command' => $job->kind === 'service_exec' && ! $private && is_string($spec['command'] ?? null) ? $spec['command'] : null,
            'machine' => ['id' => $job->machine_id, 'name' => $job->machine->name],
            'startedBy' => $job->startedByName(),
            'stale' => $job->isStale(),
            'retryable' => $job->isRetryable(),
            'updatedAt' => $job->updated_at?->toIso8601String(),
            'url' => $context->url("/machines/{$job->machine_id}?job={$job->id}"),
        ];
    }

    /**
     * A console command's output: the daemon echoes the command as the
     * first line, which is skipped.
     *
     * @return list<string>
     */
    public static function output(DaemonJob $job): array
    {
        $log = array_values(array_filter($job->log ?? [], 'is_string'));
        if (isset($log[0]) && str_starts_with($log[0], '$ ')) {
            array_shift($log);
        }
        if ($log === []) {
            $text = $job->result['stdout'] ?? $job->result['output'] ?? null;
            if (is_string($text) && $text !== '') {
                $log = preg_split('/\R/', rtrim($text, "\r\n")) ?: [];
            }
        }

        return $log;
    }
}
