<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\DeploySource;

/**
 * The deploy log's "Redeploy" (DaemonJobController@retry): a deploy sent
 * again exactly as it was, as a new job. By default the app's live
 * version; or an earlier deploy of it, to go back to that version.
 */
final class RedeployApp extends Tool
{
    public function __construct(private readonly Deploys $deploys) {}

    public function name(): string
    {
        return 'redeploy_app';
    }

    public function title(): string
    {
        return 'Redeploy an app';
    }

    public function description(): string
    {
        return 'Sends a deploy again exactly as it was sent (same code, settings, domain and any generated passwords), as the dashboard’s Redeploy button on a deploy does. Works for apps built from code and for template stacks. '
            .'By default it redeploys what’s live now: the app’s latest successful deploy. Since releases are named by their commit, the same commit replaces the running container in place, which can mean a moment of downtime. Pass job (a deploy id from list_deploys or get_app’s versions) to send an earlier deploy of the app again instead: that rolls the app back to that version, settings included. '
            .'Check with the person first: say the app, the machine and which version. A deploy still under way can’t be sent again until it has gone quiet for 10 minutes; then it is marked cancelled and sent anew. Returns the new job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app or stack: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'job' => Schema::string('A deploy of this app to send again (its id, from list_deploys or get_app). Defaults to its live version.', maxLength: 64),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'job' => ['sometimes', 'nullable', 'string', 'max:64'],
            'wait' => Deploys::waitRules(),
        ]);

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);

        if (filled($args['job'] ?? null)) {
            $job = DaemonJob::query()
                ->where('id', trim((string) $args['job']))
                ->where('machine_id', $machine->id)
                ->where('kind', 'deploy')
                ->where('service', $app)
                ->first();
            if ($job === null) {
                throw new ToolError("No deploy {$args['job']} of {$app} on {$machine->name}. list_deploys with app={$app} shows them.");
            }
        } else {
            $job = Deploys::lastDeploy($machine, $app);
            if ($job === null) {
                throw new ToolError("{$app} has no successful deploy on {$machine->name} to send again. list_deploys shows its deploys; retry a failed one by passing its id as job.");
            }
        }

        // As DaemonJobController@retry checks it.
        if (! $job->isRetryable()) {
            throw new ToolError('This deploy was sent before ServerOS kept deploy settings, so it can’t be retried. Deploy it again from the library.');
        }
        if ($job->isActive() && ! $job->isStale()) {
            throw new ToolError('This deploy is still running.');
        }
        $this->deploys->requireAvailable($machine);

        // Uploaded code is kept for a day, and for as long as it's live: an
        // older upload may be gone, and the daemon couldn't fetch it.
        $repo = (string) ($job->command['job']['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            throw new ToolError('The code uploaded for that version has been cleared, so it can’t be deployed again. Upload it again on the app’s page in the dashboard.');
        }

        if ($job->isActive()) {
            $job->forceFill([
                'state' => 'cancelled',
                'error' => ['phase' => 'panel', 'message' => 'Stopped waiting: the daemon never reported back on this job.'],
                'finished_at' => now(),
            ])->save();
        }

        $retry = $this->deploys->retry($context, $job);
        $retry = $context->waitForJob($retry, (int) ($args['wait'] ?? 0));
        $spec = $job->command['job'] ?? [];

        return [
            'jobId' => $retry->id,
            'state' => $retry->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'sentAgain' => $job->id,
            'commit' => filled($spec['repo'] ?? null) ? ($spec['commit'] ?? null) : null,
            'domains' => array_values($spec['domains'] ?? []),
            'job' => Present::job($retry, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$retry->id}"),
            'next' => 'Follow it with get_job (id '.$retry->id.') or list_deploys.',
        ];
    }
}
