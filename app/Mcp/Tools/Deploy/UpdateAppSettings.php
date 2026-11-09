<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DeploySource;

/**
 * Changes some of an app's settings (environment variables) and deploys
 * its live version again with them. Settings only reach the app when it
 * deploys, and every deploy after the first copies the last one's, so
 * this is the way to change one without touching the others.
 */
final class UpdateAppSettings extends Tool
{
    /** Variable names a frontend build bakes into the browser bundle. */
    private const string PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    public function __construct(private readonly Deploys $deploys) {}

    public function name(): string
    {
        return 'update_app_settings';
    }

    public function title(): string
    {
        return 'Change an app’s settings';
    }

    public function description(): string
    {
        return 'Changes an app’s settings (its environment variables) and deploys its live version again with them: the same code, domain, port and health check, with only the settings named here changed. set adds or replaces settings by name ({"API_URL": "https://..."}); remove takes names to drop. The rest stay as they are, and the answer only ever names settings, never their values. '
            .'The live commit is deployed again, which replaces its container in place (a moment of downtime); its data stays, since volumes and databases aren’t touched. Settings a frontend build bakes in (NEXT_PUBLIC_, VITE_ and the like) need a new build, so they’re refused here: change them with deploy_template. '
            .'Check with the person first: say the app, the machine and which settings change. app is the name it was deployed as (list_deploys or get_app shows it). Returns the queued job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'set' => ['type' => 'object', 'description' => 'Settings to add or replace, by name, like {"API_URL": "https://api.example.com"}. Values are text.', 'additionalProperties' => ['type' => 'string']],
            'remove' => Schema::array(Schema::string('A setting’s name.', maxLength: 128), 'Names of settings to remove.', maxItems: 50),
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
            'set' => ['sometimes', 'array', 'max:50'],
            'set.*' => ['nullable', 'string', 'max:10000'],
            'remove' => ['sometimes', 'array', 'max:50'],
            'remove.*' => ['string', 'max:128'],
            'wait' => Deploys::waitRules(),
        ]);

        $set = array_map(fn ($value) => (string) $value, (array) ($args['set'] ?? []));
        $remove = array_values(array_unique(array_map('strval', (array) ($args['remove'] ?? []))));
        if ($set === [] && $remove === []) {
            throw new ToolError('Say which settings to change: set some, or remove some.');
        }
        foreach ([...array_keys($set), ...$remove] as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                throw new ToolError("{$name} isn’t a setting name: use letters, digits and underscores, not starting with a digit.");
            }
            if (preg_match(self::PUBLIC_ENV, (string) $name)) {
                throw new ToolError("{$name} is read while the app is built, so changing it needs a new build: deploy it again with deploy_template.");
            }
        }
        if (($both = array_intersect(array_keys($set), $remove)) !== []) {
            throw new ToolError(implode(', ', $both).' can’t be both set and removed.');
        }

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);
        $last = Deploys::lastDeploy($machine, $app);
        if ($last === null || ! $last->isRetryable()) {
            throw new ToolError("{$app} has no successful deploy on {$machine->name} to change. list_deploys shows its deploys.");
        }

        $spec = $last->command['job'];
        $repo = (string) ($spec['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            throw new ToolError('The code uploaded for the live version has been cleared, so it can’t be deployed again. Upload it again on the app’s page in the dashboard, then change its settings.');
        }

        $env = (array) ($spec['env'] ?? []);
        if (($missing = array_values(array_diff($remove, array_keys($env)))) !== []) {
            throw new ToolError(implode(', ', $missing).' isn’t one of '.$app.'’s settings. get_app lists them.');
        }
        $changed = array_values(array_filter(array_keys($set), fn ($name) => ($env[$name] ?? null) !== $set[$name]));
        if ($changed === [] && $remove === []) {
            throw new ToolError('Those settings already have those values: nothing to change.');
        }

        $this->deploys->requireAvailable($machine);
        $spec['env'] = array_diff_key([...$env, ...$set], array_flip($remove));

        $job = $this->deploys->deploy($context, $machine, $spec);
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'commit' => filled($repo) && ! Deploys::fromUpload($repo) ? ($spec['commit'] ?? null) : null,
            'added' => array_values(array_filter($changed, fn ($name) => ! array_key_exists($name, $env))),
            'replaced' => array_values(array_filter($changed, fn ($name) => array_key_exists($name, $env))),
            'removed' => $remove,
            'settings' => array_map('strval', array_keys($spec['env'])),
            'job' => Present::job($job, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'The live version is deployed again with the new settings, replacing its container in place. Follow it with get_job (id '.$job->id.').',
        ];
    }
}
