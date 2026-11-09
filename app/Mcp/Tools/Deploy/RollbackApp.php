<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Deploys\AppVersions;
use RuntimeException;

/**
 * The app page's Roll back: start an earlier version the machine still
 * keeps, and put its data back too if asked.
 */
final class RollbackApp extends Tool
{
    public function __construct(private readonly AppVersions $versions) {}

    public function name(): string
    {
        return 'rollback_app';
    }

    public function title(): string
    {
        return 'Roll an app back';
    }

    public function description(): string
    {
        return 'Rolls an app ServerOS built from code back to an earlier version the machine still keeps (the last '.AppVersions::KEPT.'; get_app lists them under rollback): that version’s container starts again, takes over the domain once it’s healthy, and the newer one stops. Quicker than redeploy_app, which builds the version again. '
            .'with_data also puts the app’s data back as it was just before the next deploy, from the snapshot ServerOS takes before each deploy (only when get_app says withData is possible). Anything written since is replaced; a snapshot is taken first, so it can be undone with restore_backup. '
            .'Tell the person which version (its commit and when it was deployed) and whether data goes back too, get their go-ahead, then pass confirm set to the app’s name exactly. Runs as a job: pass wait (up to 25 seconds), then follow it with get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'commit' => Schema::string('The version to go back to: its commit (the first 7 characters are enough), from get_app.', maxLength: 64),
            'with_data' => Schema::boolean('Also put its data back as it was just before the next deploy. Default false.'),
            'confirm' => Schema::confirm('the app’s name'),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app', 'commit', 'confirm']);
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
        $data = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'commit' => ['required', 'string', 'min:4', 'max:64'],
            'with_data' => ['sometimes', 'boolean'],
            'confirm' => ['nullable', 'string', 'max:200'],
            'wait' => Deploys::waitRules(),
        ]);
        $machine = $context->machine($data['machine']);
        $app = Deploys::appName($context, $machine, $data['app']);

        $version = collect(AppVersions::for($machine, $app))->first(fn (array $v) => str_starts_with($v['commit'], strtolower((string) $data['commit'])));
        if ($version === null) {
            throw new ToolError("No kept version of {$app} starts with {$data['commit']}. get_app lists the ones it can go back to.");
        }
        if (($data['confirm'] ?? null) !== $app) {
            throw new ToolError("This switches {$app} on {$machine->name} back to ".substr($version['commit'], 0, 7).(($data['with_data'] ?? false) ? ', and replaces its data with the snapshot from before the next deploy' : '').'. Ask the person first, then pass confirm set to “'.$app.'” exactly.');
        }

        try {
            $job = $context->within(fn () => $this->versions->rollback($machine, $context->user, $app, $version['commit'], (bool) ($data['with_data'] ?? false)));
        } catch (RuntimeException $e) {
            throw new ToolError("Can’t roll back: {$e->getMessage()}");
        }

        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'app' => $app,
            'to' => substr($version['commit'], 0, 7),
            'withData' => (bool) ($data['with_data'] ?? false),
            'job' => Present::job($job, 10),
            'pageUrl' => $context->url("/machines/{$machine->id}/services/app:{$app}"),
        ];
    }
}
