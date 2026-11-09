<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Deploys\DeployRequest;

/**
 * A new version of an app ServerOS built from code, as the service page's
 * "new version" does (AppVersionController@store): the last deploy's
 * settings (domain, environment, port, health check) with the code
 * swapped. The dashboard swaps in an uploaded .zip; here it's a commit of
 * a public Git repository.
 */
final class DeployNewVersion extends Tool
{
    public function __construct(
        private readonly RefResolver $refs,
        private readonly Deploys $deploys,
    ) {}

    public function name(): string
    {
        return 'deploy_new_version';
    }

    public function title(): string
    {
        return 'Deploy a new version of an app';
    }

    public function description(): string
    {
        return 'Ships new code for an app ServerOS already built from code on a machine: its last successful deploy is sent again with only the code changed, so its domain, environment, port and health check stay as they are. The new version starts beside the live one and only takes over once it passes its health check; if it fails, the old one keeps serving. '
            .'Check with the person first: say the app, the machine and the commit. '
            .'app is the name it was deployed as (list_deploys or get_app; "app:<name>" and its service key work too). ref is a branch, tag or full commit of its repository (default: the default branch’s latest commit), resolved to an exact commit. '
            .'Apps whose code was uploaded as a .zip keep their upload address private: give repo (an HTTPS Git repository (public, or a private GitHub one the workspace has connected)) to build from that instead, or upload the new .zip on the app’s page in the dashboard. repo also moves a Git app to another repository. '
            .'Deploying the commit that’s already live is refused: to run the same version again, use redeploy_app. Returns the queued job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'ref' => Schema::string('The branch, tag or full 40-character commit to deploy. Defaults to the default branch’s latest commit.', maxLength: 255),
            'repo' => Schema::string('Build from this HTTPS Git repository (public, or a private GitHub one the workspace has connected) instead of the app’s current one (needed for apps uploaded as a .zip).', maxLength: 500),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'repo' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'wait' => Deploys::waitRules(),
        ], ['repo.url' => 'repo must be a URL, like https://github.com/you/app.git.']);

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);

        $last = Deploys::lastCodeDeploy($machine, $app);
        if ($last === null) {
            throw new ToolError("{$app} on {$machine->name} wasn’t deployed from code, so it has no code to replace. Ready-made stacks are redeployed with redeploy_app, or deployed again with deploy_template.");
        }

        $this->deploys->requireAvailable($machine);

        $current = (string) ($last->command['job']['repo'] ?? '');
        $repo = filled($args['repo'] ?? null) ? trim((string) $args['repo']) : null;
        if ($repo === null) {
            if (Deploys::fromUpload($current)) {
                throw new ToolError("{$app}’s code was uploaded as a .zip, so there’s no repository to fetch a new version from. Upload the new version on its page in the dashboard (".$context->url("/machines/{$machine->id}/services/app:{$app}").'), or give repo: an HTTPS Git repository (public, or a private GitHub one the workspace has connected) to build it from instead.');
            }
            $repo = $current;
        }
        Deploys::check(fn () => DeployRequest::assertPublicRepo($repo));

        $resolved = $this->refs->resolve($repo, $args['ref'] ?? null, $context->workspace);
        $live = strtolower((string) ($last->command['job']['commit'] ?? ''));
        if ($resolved['commit'] === $live && $repo === $current) {
            throw new ToolError("{$app} is already running ".substr($live, 0, 7).($resolved['ref'] !== null ? " (the latest on {$resolved['ref']})" : '').'. To deploy the same version again, use redeploy_app.');
        }

        $spec = [...$last->command['job'], 'repo' => $repo, 'commit' => $resolved['commit']];
        $job = $this->deploys->deploy($context, $machine, $spec);
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'repo' => $repo,
            'ref' => $resolved['ref'],
            'commit' => $resolved['commit'],
            'previousCommit' => $live,
            'domains' => array_values($spec['domains'] ?? []),
            'job' => Present::job($job, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'The new version builds beside the live one and takes over once it passes its health check. Follow it with get_job (id '.$job->id.') or list_deploys.',
        ];
    }
}
