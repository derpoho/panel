<?php

namespace App\Mcp\Tools\Deploy;

use App\Http\Controllers\AppVersionController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DeploySource;
use App\Support\Deploys\DeployRequest;
use App\Support\DeploySources\DeploySourcePacker;

/**
 * Move an app to a new domain, as the service page's "Change domain" does
 * (AppVersionController@domain): the same code and settings, served
 * somewhere else, built as a fresh release beside the live one.
 */
final class SetAppDomain extends Tool
{
    public function __construct(
        private readonly Deploys $deploys,
        private readonly DeploySourcePacker $packer,
    ) {}

    public function name(): string
    {
        return 'set_app_domain';
    }

    public function title(): string
    {
        return 'Change an app’s domain';
    }

    public function description(): string
    {
        return 'Moves an app ServerOS built from code to a new domain, as the dashboard’s “Change domain” on its page does: the same code and settings are deployed as a fresh release served at https://<domain> (the machine gets the certificate itself once DNS points there: check with check_domain first and tell the person which record to add). Once it passes its health check it takes over and the old domain stops serving it. '
            .'Check with the person first. move_settings (default true, as in the dashboard) also changes settings that mention the old domain (like APP_URL or PUBLIC_URL) to the new one; get_app lists which settings mention it. '
            .'Apps built from a Git repository deploy their live commit again, which replaces the container in place (a moment of downtime); apps uploaded as a .zip need their uploaded code still on hand. Returns the queued job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'domain' => Schema::string('The new domain, like shop.example.com.', maxLength: 253),
            'wildcard' => Schema::boolean('Also serve every subdomain one level under it (*.domain), each getting its own certificate the first time it’s visited. Needs a wildcard DNS record (*.domain) pointing at the machine. Left out, the app keeps its current setting.'),
            'move_settings' => Schema::boolean('Also change settings that mention the old domain to the new one (default true).'),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app', 'domain']);
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
            'domain' => ['required', 'string', 'max:300'],
            'wildcard' => ['sometimes', 'boolean'],
            'move_settings' => ['sometimes', 'boolean'],
            'wait' => Deploys::waitRules(),
        ]);

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);

        $data = $this->validate(['domain' => DeployRequest::bareDomain($args['domain'])], [
            'domain' => ['required', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
        ], ['domain.regex' => 'Enter a domain like app.example.com.']);

        $last = Deploys::lastCodeDeploy($machine, $app);
        if ($last === null) {
            throw new ToolError('This service wasn’t deployed from code.');
        }

        $job = $last->command['job'];
        $old = $job['domains'] ?? [];
        $wildcard = array_key_exists('wildcard', $args) ? (bool) $args['wildcard'] : DeployRequest::isWildcard($old);
        $domains = DeployRequest::domains($data['domain'], $wildcard);
        if ($old === $domains) {
            throw new ToolError('It’s already on that domain.');
        }
        DeployRequest::assertWildcard($machine, $data['domain'], $wildcard, 'domain');

        // An app built from Git deploys its live commit again, which replaces
        // its container in place; uploaded code is packed under a new commit.
        $fromGit = filled($job['repo'] ?? null) && ! Deploys::fromUpload($job['repo']);
        $source = $fromGit ? null : DeploySource::fromRepoUrl($job['repo'] ?? null);
        if (! $fromGit && ($source === null || $source->workspace_id !== $context->workspace->id || ! $source->usable())) {
            throw new ToolError('The uploaded code for this app has been cleared. Upload it again as a new version, then change the domain.');
        }

        $this->deploys->requireAvailable($machine);

        $moved = [];
        $oldPlain = DeployRequest::plainDomains($old);
        if (($args['move_settings'] ?? true) && $oldPlain !== [] && $oldPlain !== [$data['domain']]) {
            $moved = AppVersionController::mentions($last);
            $swap = fn (string $value) => str_replace($oldPlain, $data['domain'], $value);
            $job['env'] = array_map(fn ($value) => is_string($value) ? $swap($value) : $value, $job['env'] ?? []);
            $job['files'] = array_map(fn ($value) => is_string($value) ? $swap($value) : $value, $job['files'] ?? []);
        }

        $job['domains'] = $domains;
        if ($source !== null) {
            $job['commit'] = $this->packer->recommit($source, 'Moved to '.$data['domain']);
        }

        $record = $this->deploys->deploy($context, $machine, $job);
        $record = $context->waitForJob($record, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $record->id,
            'state' => $record->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'domain' => $data['domain'],
            'wildcard' => $wildcard,
            'previousDomains' => array_values($old),
            'url' => "https://{$data['domain']}",
            'movedSettings' => $moved,
            'job' => Present::job($record, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$record->id}"),
            'next' => 'It deploys as a fresh release and takes over once healthy; HTTPS starts once the domain’s DNS points at '.($machine->ip_address ?: 'the machine').' (check_domain)'.($wildcard ? ', and every subdomain once a *.'.$data['domain'].' record does too: each gets its certificate on its first visit' : '').'. Follow it with get_job (id '.$record->id.').',
        ];
    }
}
