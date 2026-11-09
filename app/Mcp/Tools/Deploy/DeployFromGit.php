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
 * A public repository built with its own Dockerfile, as the machine's
 * deploy endpoint takes it (MachineDeployController::__invoke). The
 * dashboard sends an exact commit; here a branch or tag is resolved to one
 * first, the way its deploy dialog does.
 */
final class DeployFromGit extends Tool
{
    public function __construct(
        private readonly RefResolver $refs,
        private readonly Deploys $deploys,
    ) {}

    public function name(): string
    {
        return 'deploy_from_git';
    }

    public function title(): string
    {
        return 'Deploy a Git repository';
    }

    public function description(): string
    {
        return 'Builds an HTTPS Git repository (public, or a private GitHub one the workspace has connected) with the Dockerfile at its root and runs it on a machine: the daemon clones the exact commit, builds it, starts it beside any previous version, checks health_path answers 200, and only then switches over (the old version keeps serving if the new one fails). '
            .'Check with the person before deploying: say the machine, the repository, the commit and the domain. '
            .'ref is a branch, tag or full commit (default: the default branch’s latest commit); it is resolved to an exact commit, which the answer gives. GitHub repositories are resolved through GitHub, others by reading the repository’s branches and tags over HTTPS. '
            .'port is the port the app listens on inside its container. domain (optional) serves it at https://domain, with a certificate the machine gets itself once DNS points there (check_domain). This sets no environment variables: for those, or for frameworks without a Dockerfile, use deploy_template (template custom-dockerfile builds your own Dockerfile and takes settings). '
            .'If something is already deployed under name on the machine, this replaces it, so it refuses unless replace is true: for a new version of an existing app use deploy_new_version. '
            .'Returns the queued job and the deploy’s details; it takes a few minutes, so follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'repo' => Schema::string('The HTTPS Git repository (public, or a private GitHub one the workspace has connected), like https://github.com/you/app.git. No credentials in it.', maxLength: 500),
            'ref' => Schema::string('The branch, tag or full 40-character commit to deploy. Defaults to the default branch’s latest commit.', maxLength: 255),
            'name' => Schema::string('The name to deploy it as: lowercase letters, digits and dashes, starting with a letter (at most 63). Defaults to the repository’s name.', maxLength: 63),
            'port' => Schema::integer('The port the app listens on inside its container.', 1, 65535),
            'health_path' => Schema::string('A path that answers 200 once the app is up, like /health (default /).', maxLength: 200),
            'domain' => Schema::string('The domain to serve it at, like app.example.com. Leave out for none.', maxLength: 253),
            'wildcard' => Schema::boolean('Also serve every subdomain one level under the domain (*.domain), each getting its own certificate the first time it’s visited. Needs domain, and a wildcard DNS record (*.domain) pointing at the machine.'),
            'replace' => Schema::boolean('Deploy even though something is already deployed under this name on the machine, replacing it. Only after the person agrees.'),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'repo', 'port']);
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
            'repo' => ['required', 'string', 'max:500'],
            'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:63'],
            'port' => ['required'],
            'health_path' => ['sometimes', 'nullable', 'string', 'max:200'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:300'],
            'wildcard' => ['sometimes', 'boolean'],
            'replace' => ['sometimes', 'boolean'],
            'wait' => Deploys::waitRules(),
        ]);

        $machine = $context->machine($args['machine']);
        $this->deploys->requireAvailable($machine);

        $repo = trim($args['repo']);
        $input = array_filter([
            'service' => filled($args['name'] ?? null) ? trim((string) $args['name']) : (DetectRepository::serviceName($repo) ?: 'app'),
            'repo' => $repo,
            'port' => $args['port'],
            'health_path' => filled($args['health_path'] ?? null) ? $args['health_path'] : '/',
            'domain' => DeployRequest::bareDomain($args['domain'] ?? null),
            'wildcard' => ($args['wildcard'] ?? false) ? true : null,
        ], fn ($value) => $value !== null && $value !== '');

        // Everything but the commit first, so nothing is looked up for a deploy that can't go ahead.
        $rules = DeployRequest::gitRules();
        $this->validate($input, array_diff_key($rules, ['commit' => true]), Deploys::messages());
        Deploys::check(fn () => DeployRequest::assertPublicRepo($repo));

        $resolved = $this->refs->resolve($repo, $args['ref'] ?? null, $context->workspace);
        $data = $this->validate([...$input, 'commit' => $resolved['commit']], $rules, Deploys::messages());

        if (! ($args['replace'] ?? false) && ($existing = Deploys::existing($machine, $data['service'])) !== null) {
            throw new ToolError("{$existing} Deploying this as {$data['service']} would replace it. Choose another name, use deploy_new_version to ship new code for that app, or, if the person agrees to replace it, call again with replace: true.");
        }

        Deploys::check(fn () => DeployRequest::assertDocker($machine, $context->user));
        Deploys::check(fn () => DeployRequest::assertWildcard($machine, $data['domain'] ?? null, (bool) ($data['wildcard'] ?? false)));

        $spec = DeployRequest::gitJob($data);
        $job = $this->deploys->deploy($context, $machine, $spec);
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'name' => $spec['service'],
            'repo' => $spec['repo'],
            'ref' => $resolved['ref'],
            'commit' => $spec['commit'],
            'port' => $spec['port'],
            'healthPath' => $spec['health']['path'],
            'domain' => $spec['domains'][0] ?? null,
            'wildcard' => DeployRequest::isWildcard($spec['domains']),
            'url' => isset($spec['domains'][0]) ? "https://{$spec['domains'][0]}" : $machine->localAddress($spec['port']),
            'job' => Present::job($job, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'It builds on the machine and passes its health check before it goes live; that takes a few minutes. Follow it with get_job (id '.$job->id.') or list_deploys.',
        ];
    }
}
