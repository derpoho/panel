<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\AppInstall;
use App\Support\Deploys\DeployRequest;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;

/**
 * "Deploy service" from the template library, as the dashboard's dialog
 * sends it (MachineDeployController::deployTemplate): a ready-made stack,
 * or an app framework building a public repository.
 */
final class DeployTemplate extends Tool
{
    public function __construct(
        private readonly ServiceTemplateCatalog $catalog,
        private readonly RefResolver $refs,
        private readonly Deploys $deploys,
    ) {}

    public function name(): string
    {
        return 'deploy_template';
    }

    public function title(): string
    {
        return 'Deploy from a template';
    }

    public function description(): string
    {
        return 'Deploys a service template on a machine, exactly as the dashboard’s “Deploy service” dialog does: a ready-made stack (postgres, wordpress, a game server…: kind "stack") or an app framework that builds an HTTPS Git repository (public, or a private GitHub one the workspace has connected) with ServerOS’s Dockerfile (laravel, nextjs, django…: kind "app"). list_templates shows the keys and each template’s settings; detect_repository picks the template for a GitHub repository. '
            .'Check with the person before deploying: say the machine, the template, the name and the domain. It runs on their real machine. '
            .'name is what it’s deployed as (lowercase letters, digits and dashes; defaults to the repository’s or template’s name, as the dialog fills it in). If something is already deployed under that name on the machine, this replaces it (a new release of it, with these settings), so it refuses unless replace is true: to ship new code for an existing app use deploy_new_version instead, and confirm replacing with the person. '
            .'For app templates give repo, and ref: a branch, tag or commit (the default branch’s latest commit when left out); it is resolved to an exact commit, which is what gets deployed. '
            .'domain (web templates only) serves it at https://domain with a certificate the machine gets itself once DNS points there (check_domain shows whether it does). Without a domain a stack only listens on the machine’s 127.0.0.1; the answer says how to reach it (localUrl, and an SSH tunnel command). '
            .'env sets the template’s settings by key; leave secrets empty to have them generated. Settings marked accept (a licence like Minecraft’s EULA) must be true: ask the person to accept it, never accept it for them. Other environment variables go in extra_env (KEY=value lines, or an object). '
            .'Returns the queued job, the URL it will open at, its port and notes, and `generated`: passwords and keys made for it. They are shown ONCE: give them to the person now; ServerOS won’t show them again. Follow the deploy with get_job or list_deploys; it takes a few minutes.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'template' => Schema::string('The template’s key, as list_templates shows it (like postgres, wordpress, laravel).', maxLength: 100),
            'name' => Schema::string('The name to deploy it as: lowercase letters, digits and dashes, starting with a letter (at most 63). Defaults to the repository’s or template’s name.', maxLength: 63),
            'repo' => Schema::string('App templates only: the HTTPS Git repository (public, or a private GitHub one the workspace has connected) to build, like https://github.com/you/app.git. No credentials.', maxLength: 500),
            'ref' => Schema::string('App templates only: the branch, tag or full commit to deploy. Defaults to the default branch’s latest commit.', maxLength: 255),
            'port' => Schema::integer('The port it listens on, for templates whose port can change (list_templates says portEditable). Defaults to the template’s.', 1, 65535),
            'health_path' => Schema::string('App templates only: the path that answers 200 once the app is up, like /health. Defaults to the template’s (/up for Laravel, which only Laravel 11 and newer have: detect_repository says which to use).', maxLength: 200),
            'php' => Schema::string('PHP templates only (laravel, php, symfony…): the PHP version to build on. Defaults to 8.4; an older app needs the version detect_repository suggests, or it can crash on start.', RepositoryDetector::PHP_VERSIONS),
            'domain' => Schema::string('Web templates only: the domain to serve it at, like app.example.com. Leave out for none.', maxLength: 253),
            'wildcard' => Schema::boolean('Also serve every subdomain one level under the domain (*.domain), each getting its own certificate the first time it’s visited. Needs domain, and a wildcard DNS record (*.domain) pointing at the machine.'),
            'env' => ['type' => 'object', 'description' => 'The template’s settings by key (list_templates with template shows them), like {"WP_TITLE": "Field notes"}. Values are text; a setting to accept takes "true", once the person has accepted it. Leave secrets out to have them generated.', 'additionalProperties' => ['type' => 'string']],
            'extra_env' => Schema::string('More environment variables, as KEY=value lines (like "MAIL_MAILER=log\nAPI_URL=https://api.example.com").', maxLength: 10000),
            'replace' => Schema::boolean('Deploy even though something is already deployed under this name on the machine, replacing it. Only after the person agrees.'),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'template']);
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
            'template' => ['required', 'string', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:63'],
            'repo' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'health_path' => ['sometimes', 'nullable', 'string', 'max:200'],
            'php' => ['sometimes', 'nullable', 'string', 'max:10'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:300'],
            'wildcard' => ['sometimes', 'boolean'],
            'env' => ['sometimes', 'nullable', 'array'],
            'extra_env' => ['sometimes', 'nullable'],
            'replace' => ['sometimes', 'boolean'],
            'wait' => Deploys::waitRules(),
        ]);

        $machine = $context->machine($args['machine']);
        $this->deploys->requireAvailable($machine);

        $template = $this->catalog->find(trim($args['template']));
        if ($template === null) {
            throw new ToolError("No template “{$args['template']}”. list_templates shows them.");
        }

        $this->refuseWhatDoesNotApply($template, $args);
        $env = $this->env($template, $args['env'] ?? []);
        $extra = $this->extraEnv($args['extra_env'] ?? null);

        $repo = null;
        if ($template->isApp()) {
            $repo = trim((string) ($args['repo'] ?? ''));
            if ($repo === '') {
                throw new ToolError("{$template->name} builds your code: give repo, an HTTPS Git repository (public, or a private GitHub one the workspace has connected) like https://github.com/you/app.git.");
            }
        }

        $input = array_filter([
            'service' => filled($args['name'] ?? null) ? trim((string) $args['name']) : self::defaultName($template, $repo),
            'port' => $args['port'] ?? null,
            'repo' => $repo,
            'health_path' => $args['health_path'] ?? null,
            'php' => $args['php'] ?? null,
            'domain' => DeployRequest::bareDomain($args['domain'] ?? null),
            'wildcard' => ($args['wildcard'] ?? false) ? true : null,
            'env' => $env,
            'extra_env' => $extra,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        // Everything but the commit first, so nothing is looked up for a deploy that can't go ahead.
        $rules = DeployRequest::templateRules($template);
        $this->validate($input, array_diff_key($rules, ['commit' => true]), Deploys::messages());

        $resolved = null;
        if ($template->isApp()) {
            Deploys::check(fn () => DeployRequest::assertPublicRepo((string) $repo));
            $resolved = $this->refs->resolve((string) $repo, $args['ref'] ?? null, $context->workspace);
            $input['commit'] = $resolved['commit'];
        }

        $data = $this->validate($input, $rules, Deploys::messages());

        if (! ($args['replace'] ?? false) && ($existing = Deploys::existing($machine, $data['service'])) !== null) {
            throw new ToolError("{$existing} Deploying {$template->name} as {$data['service']} would replace it. Choose another name, use deploy_new_version to ship new code for an app, or, if the person agrees to replace it, call again with replace: true.");
        }

        $deployment = Deploys::check(fn () => new TemplateDeployment($template, $machine, $data));
        Deploys::check(fn () => DeployRequest::assertDocker($machine, $context->user));
        Deploys::check(fn () => DeployRequest::assertWildcard($machine, $template->web ? ($data['domain'] ?? null) : null, (bool) ($data['wildcard'] ?? false)));

        $job = $this->deploys->deploy($context, $machine, $deployment->job());
        AppInstall::record($machine, $context->user, $template->slug, $job->id, 'mcp');
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));
        $result = DeployRequest::templateResult($deployment, $data);

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'name' => $data['service'],
            ...$result,
            'templateKey' => $template->slug,
            'repo' => $repo,
            'ref' => $resolved['ref'] ?? null,
            'commit' => $template->isApp() ? $deployment->commit() : null,
            'domain' => $template->web ? ($data['domain'] ?? null) : null,
            'wildcard' => DeployRequest::isWildcard($deployment->job()['domains']),
            'generatedNote' => $result['generated'] === [] ? null : 'These were generated for this deploy and are shown only now: give them to the person to keep somewhere safe.',
            'keptNote' => $deployment->kept === [] ? null : 'These settings were kept from the last deploy of '.$data['service'].' rather than generated again, so its data and anything connected to it keep working.',
            'job' => Present::job($job, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'It builds and starts on the machine, then passes its health check before it goes live; that takes a few minutes. Follow it with get_job (id '.$job->id.') or list_deploys.',
        ];
    }

    /**
     * Arguments that don't fit the template: say so rather than ignore them.
     *
     * @param  array<string, mixed>  $args
     */
    private function refuseWhatDoesNotApply(ServiceTemplate $template, array $args): void
    {
        if (filled($args['php'] ?? null) && ! TemplateDeployment::usesPhp($template)) {
            throw new ToolError("{$template->name} doesn’t run on PHP: leave out php.");
        }
        if (! $template->isApp()) {
            foreach (['repo', 'ref', 'health_path'] as $key) {
                if (filled($args[$key] ?? null)) {
                    throw new ToolError("{$template->name} is a ready-made stack, not built from code: leave out {$key}.");
                }
            }
        }
        if (! $template->portEditable && isset($args['port']) && (int) $args['port'] !== $template->port) {
            throw new ToolError("{$template->name} always listens on port {$template->port}: leave out port.");
        }
        if (! $template->web && filled($args['domain'] ?? null)) {
            throw new ToolError("{$template->name} isn’t a web service, so it can’t have a domain: leave out domain.");
        }
    }

    /**
     * The template's settings as the dialog sends them: text, with ticked
     * boxes as "true". Keys the template doesn't have are refused, so a
     * typo doesn't quietly fall back to a default.
     *
     * @param  array<mixed>  $given
     * @return array<string, string>
     */
    private function env(ServiceTemplate $template, array $given): array
    {
        $keys = array_column($template->env, 'key');
        $env = [];
        foreach ($given as $key => $value) {
            if (! is_string($key) || ! in_array($key, $keys, true)) {
                throw new ToolError("{$template->name} has no setting “{$key}”".($keys === [] ? '' : ' (it has '.implode(', ', $keys).')').'. Put other environment variables in extra_env.');
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new ToolError("env.{$key} must be text, a number, or true/false.");
            }
            $env[$key] = match (true) {
                $value === true => 'true',
                $value === false, $value === null => '',
                default => (string) $value,
            };
        }

        return $env;
    }

    /** KEY=value lines, from lines or an object. */
    private function extraEnv(mixed $extra): ?string
    {
        if ($extra === null || $extra === '' || $extra === []) {
            return null;
        }
        if (is_string($extra)) {
            return $extra;
        }
        if (! is_array($extra)) {
            throw new ToolError('extra_env must be KEY=value lines, or an object of names to values.');
        }

        $lines = [];
        foreach ($extra as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new ToolError('extra_env names must be letters, digits and underscores, not starting with a digit (like API_URL).');
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new ToolError("extra_env.{$key} must be text or a number.");
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (preg_match('/[\r\n]/', $value)) {
                throw new ToolError("extra_env.{$key} must be on one line.");
            }
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines);
    }

    /** As the dialog fills it in: the repository's name, or the template's. */
    public static function defaultName(ServiceTemplate $template, ?string $repo): string
    {
        $fromTemplate = substr((string) preg_replace('/[^a-z0-9-]/', '-', $template->slug === 'custom-dockerfile' ? 'app' : $template->slug), 0, 63);

        return $repo !== null && $repo !== '' ? (DetectRepository::serviceName($repo) ?: $fromTemplate) : $fromTemplate;
    }
}
