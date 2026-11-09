<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Support\Machines\PackageRollouts;
use Illuminate\Validation\ValidationException;

/**
 * Roll OS updates out to many machines, as the Updates page's Roll out
 * dialog does.
 */
final class CreatePackageRollout extends Tool
{
    public function __construct(private readonly PackageRollouts $rollouts) {}

    public function name(): string
    {
        return 'create_package_rollout';
    }

    public function title(): string
    {
        return 'Roll out OS updates to machines';
    }

    public function description(): string
    {
        return 'Installs operating system package updates on several machines as one rollout, like the Updates page. Choose packages (names as list_package_updates shows them) or all=true for every waiting update; mode "latest" installs the newest each machine offers, "exact" installs the version given for each package, which must be one list_package_updates shows offered on every chosen machine (versions differ between OS releases, so pick machines on the same one). '
            .'machines: ids (default: every machine with one of the packages waiting). batch_size installs on that many machines at a time, each batch after the one before has finished (leave out for all at once); stop_on_failure (default true) stops before the next batch when one fails. reboot "if_required" restarts a machine whose install needs it, with its alerts held; "never" (default) leaves that to the person. scheduled_for (ISO 8601) starts it later. Machines that are offline at their turn are skipped, as are machines whose daemon is too old for exact versions. '
            .'Package upgrades can restart the services they belong to. Call it first with confirm empty to see which machines it would run on (nothing is changed), tell the person, then call again with confirm set to the machine count as it says (like "3 machines"). get_package_rollout follows it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'packages' => Schema::array(Schema::object([
                'name' => Schema::string('The package name, like openssl.', maxLength: 200),
                'version' => Schema::string('The exact version (mode "exact" only), as list_package_updates lists it.', maxLength: 128),
            ], ['name']), 'The packages to install. Leave out with all=true.', 500),
            'all' => Schema::boolean('Every waiting update on each machine, to its latest version.'),
            'mode' => Schema::string('latest (default) or exact.', ['latest', 'exact']),
            'allow_downgrade' => Schema::boolean('Exact versions only: allow going back to an older version.'),
            'machines' => Schema::array(Schema::integer('A machine id.'), 'The machines, by id. Default: every machine with one of the packages waiting.', 2000),
            'batch_size' => Schema::integer('Machines at a time (default: all at once).', 1, 1000),
            'stop_on_failure' => Schema::boolean('Stop before the next batch when a machine fails (default true).'),
            'reboot' => Schema::string('never (default) or if_required.', ['never', 'if_required']),
            'scheduled_for' => Schema::string('When to start, ISO 8601, within 60 days. Leave out to start now.', maxLength: 40),
            'name' => Schema::string('A name for it (default: from the packages).', maxLength: 120),
            'confirm' => Schema::confirm('the machine count, like “3 machines”'),
        ], ['confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $input = [...$arguments, 'mode' => $arguments['mode'] ?? 'latest'];
        unset($input['confirm']);
        if (! isset($input['packages']) && ! ($input['all'] ?? false)) {
            throw new ToolError('Say which packages to install, or all=true for every waiting update.');
        }

        try {
            ['machines' => $machines, 'packages' => $packages] = $this->rollouts->plan($context->workspace, $input);
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', $e->validator->errors()->all()));
        }

        $count = $machines->count().($machines->count() === 1 ? ' machine' : ' machines');
        $given = is_string($arguments['confirm'] ?? null) ? trim($arguments['confirm']) : '';
        if ($given !== $count) {
            $what = ($arguments['all'] ?? false) ? 'every waiting update' : implode(', ', array_map(fn (array $p) => trim($p['name'].' '.($p['version'] ?? '')), $packages));
            $on = $machines->take(20)->map(fn (Machine $m) => $m->name)->implode(', ').($machines->count() > 20 ? ' and '.($machines->count() - 20).' more' : '');
            throw new ToolError(($given === '' ? '' : 'confirm doesn’t match. ')."This installs {$what} on {$count}: {$on}. Package upgrades can restart the services they belong to. Check with the person first, then call again with confirm set to “{$count}”. Nothing was changed.");
        }
        ServerWork::throttle($context, 'package-rollout', 10);

        try {
            $rollout = $context->within(fn () => $this->rollouts->create($context->workspace, $context->user, $input));
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', $e->validator->errors()->all()));
        }

        return [
            'rollout' => PackageRollouts::detail($rollout),
            'hint' => 'get_package_rollout follows it. Machines are started a batch at a time, checked every minute.',
            'url' => $context->url('/updates?view=rollouts'),
        ];
    }
}
