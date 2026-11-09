<?php

namespace App\Mcp\Tools\Deploy;

use App\Http\Controllers\AppVersionController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\AppVersions;

/**
 * One app ServerOS deployed on a machine, as its service page shows it:
 * the live version, where its code comes from, its domains and settings
 * (names only), and the versions before it.
 */
final class GetApp extends Tool
{
    public function name(): string
    {
        return 'get_app';
    }

    public function title(): string
    {
        return 'Get a deployed app';
    }

    public function description(): string
    {
        return 'Shows an app (or template stack) ServerOS deployed on a machine: the live version (its deploy id, commit, when and by whom), where its code comes from (a Git repository’s address, or an uploaded .zip and whether it’s still on hand), its domains and URL, port and health check, the names of its settings (never their values) and which of them mention its domain, its running containers as list_services shows them, its earlier versions (successful deploys, newest first, and whether each one’s container is still kept on the machine for rollback), `rollback` (the versions rollback_app can switch to, and whether their data can go back too: withData, from a snapshot taken at dataFrom), snapshotBeforeDeploy (whether deploys snapshot its data first), `database` (the database snapshotted with it: setting auto follows its settings like DATABASE_URL, current is the one that comes to now), and its latest deploys with their state. '
            .'Use it before deploy_new_version, rollback_app, redeploy_app (an earlier version’s id builds it again) or set_app_domain. app is the name it was deployed as (list_deploys shows them), app:<name>, or one of its services.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), app:<name>, or one of its services’ keys or names.', maxLength: 300),
        ], ['machine', 'app']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
        ]);
        $machine = $context->machine($data['machine']);
        $app = Deploys::appName($context, $machine, $data['app']);

        $live = Deploys::lastDeploy($machine, $app);
        $recent = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
            ->with('user:id,name')->latest()->limit(5)->get();
        $recent->each(fn (DaemonJob $job) => $job->setRelation('machine', $machine));
        $spec = ($live ?? $recent->first())?->command['job'] ?? [];
        $repo = (string) ($spec['repo'] ?? '');
        $fromCode = $repo !== '';
        $upload = Deploys::upload($repo);
        $domains = array_values($spec['domains'] ?? []);
        $port = isset($spec['port']) ? (int) $spec['port'] : null;

        $liveIds = $live === null ? [] : [$app => $live->id];

        return [
            'app' => $app,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'kind' => $fromCode ? 'app' : 'stack',
            'live' => $live === null ? null : [
                'deployId' => $live->id,
                'commit' => $fromCode ? ($live->command['job']['commit'] ?? null) : null,
                'deployedAt' => $live->created_at?->toIso8601String(),
                'by' => $live->startedByName(),
            ],
            'source' => match (true) {
                ! $fromCode => null,
                Deploys::fromUpload($repo) => [
                    'type' => 'upload',
                    'filename' => $upload?->filename,
                    'stillOnHand' => $upload?->usable() ?? false,
                ],
                default => ['type' => 'git', 'repo' => $repo],
            },
            'domains' => $domains,
            'url' => $domains !== [] ? "https://{$domains[0]}" : ($port !== null ? $machine->localAddress($port) : null),
            'port' => $port,
            'healthPath' => $spec['health']['path'] ?? null,
            'settings' => array_map('strval', array_keys((array) ($spec['env'] ?? []))),
            'settingsMentioningDomain' => $live !== null && $fromCode ? AppVersionController::mentions($live) : [],
            'canDeployNewVersion' => $fromCode && $live !== null,
            'canChangeDomain' => $fromCode && $live !== null && (filled($repo) && ! Deploys::fromUpload($repo) || $upload !== null && $upload->workspace_id === $context->workspace->id && $upload->usable()),
            'services' => $this->services($context, $machine, $app, $fromCode),
            'versions' => $fromCode ? $this->versions($machine, $app, $live) : [],
            'snapshotBeforeDeploy' => $machine->snapshotsBeforeDeploy($app),
            'database' => $fromCode && $live !== null ? AppVersionController::databaseProps($machine, $live) : null,
            'rollback' => $fromCode ? array_map(fn (array $v) => [
                'commit' => $v['commit'],
                'live' => $v['live'],
                'withData' => $v['restore'] !== [],
                'dataFrom' => $v['dataAt'],
            ], AppVersions::for($machine, $app)) : [],
            'recentDeploys' => $recent->map(fn (DaemonJob $job) => Deploys::present($job, $context, $liveIds))->values()->all(),
            'pageUrl' => $context->url($fromCode ? "/machines/{$machine->id}/services/app:{$app}" : "/machines/{$machine->id}"),
            'note' => $live === null ? 'No deploy of it has succeeded yet: recentDeploys shows how the latest went.' : null,
        ];
    }

    /**
     * Its containers in the latest scan: the live version of an app, or
     * every container of a stack.
     *
     * @return list<array<string, mixed>>
     */
    private function services(McpContext $context, Machine $machine, string $app, bool $fromCode): array
    {
        if ($fromCode) {
            $found = AppReleases::find($machine, $app);

            return $found === null ? [] : [Present::service($machine, $context->describeService($machine, $found), $context)];
        }

        return array_values($context->services($machine)
            ->filter(fn (array $s) => ($s['details']['compose_project'] ?? null) === "serveros-{$app}")
            ->map(fn (array $s) => Present::service($machine, $context->describeService($machine, $s), $context))
            ->all());
    }

    /**
     * Its successful deploys from code, newest first: the live one and the
     * ones it replaced, with whether each one's container is still on the
     * machine (the daemon keeps them a while, for rollback).
     *
     * @return list<array<string, mixed>>
     */
    private function versions(Machine $machine, string $app, ?DaemonJob $live): array
    {
        $containers = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            $release = is_array($service) ? AppReleases::parse($service['name'] ?? null) : null;
            if ($release !== null && $release['app'] === $app) {
                $containers[$release['commit']] = $service['status'] ?? 'unknown';
            }
        }

        return array_values(DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)->where('state', 'succeeded')
            ->with('user:id,name')->latest()->limit(10)->get()
            ->filter(fn (DaemonJob $job) => filled($job->command['job']['repo'] ?? null))
            ->map(function (DaemonJob $job) use ($containers, $live): array {
                $commit = (string) ($job->command['job']['commit'] ?? '');
                $repo = (string) ($job->command['job']['repo'] ?? '');

                return [
                    'deployId' => $job->id,
                    'commit' => $commit,
                    'live' => $job->id === $live?->id,
                    'deployedAt' => $job->created_at?->toIso8601String(),
                    'by' => $job->startedByName(),
                    'source' => Deploys::fromUpload($repo) ? 'upload' : 'git',
                    'repo' => Deploys::publicRepo($repo),
                    'domains' => array_values($job->command['job']['domains'] ?? []),
                    'container' => $containers[substr($commit, 0, 12)] ?? null,
                ];
            })->all());
    }
}
