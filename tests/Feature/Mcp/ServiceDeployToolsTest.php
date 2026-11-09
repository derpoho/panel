<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\DeployTools;
use App\Mcp\Tools\ServiceTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [ServiceTools::class, DeployTools::class]));

function serviceToolsSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.2.0',
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx.service', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'details' => []],
        ]],
    ])->save();

    return [$workspace, $machine, mcpKey($workspace)];
}

test('every tool group loads together: unique names, valid schemas, honest annotations', function () {
    config()->set('mcp.groups', (require config_path('mcp.php'))['groups']);

    $tools = app(ToolRegistry::class)->all();
    $names = array_values(array_map(fn ($tool) => $tool->name(), $tools));
    expect($names)->toBe(array_values(array_unique($names)))
        ->and(count($names))->toBeGreaterThan(80);

    foreach ($tools as $tool) {
        $definition = $tool->definition();
        expect($definition['name'])->toMatch('/^[a-z][a-z0-9_]{2,63}$/')
            ->and($definition['inputSchema']['type'])->toBe('object')
            ->and($definition['description'])->not->toBe('');
        if ($tool->destructive()) {
            expect($definition['annotations']['readOnlyHint'])->toBeFalse();
        }
    }
});

test('services are listed, renamed and restarted as the dashboard does, inside the workspace only', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();

    $list = mcpCall($workspace, $key, 'list_services', ['machine' => 'web-1']);
    expect($list['error'])->toBeFalse()->and($list['text'])->toContain('docker:aaa')->toContain('nginx');

    $renamed = mcpCall($workspace, $key, 'rename_service', ['machine' => 'web-1', 'service' => 'nginx', 'label' => 'Web server']);
    expect($renamed['error'])->toBeFalse()
        ->and(Activity::query()->where('action', 'service.rename')->sole()->meta)->toMatchArray(['via' => 'mcp']);
    expect(mcpCall($workspace, $key, 'get_service', ['machine' => 'web-1', 'service' => 'Web server'])['text'])->toContain('systemd:nginx.service');

    $restart = mcpCall($workspace, $key, 'restart_service', ['machine' => 'web-1', 'service' => 'docker:aaa']);
    expect($restart['error'])->toBeFalse();
    // Marked as it's written, not patched afterwards: the source says MCP too.
    $restarted = Activity::query()->where('action', 'service.restart')->sole();
    expect($restarted->source)->toBe('mcp')
        ->and($restarted->meta)->toMatchArray(['via' => 'mcp', 'client' => 'Test key']);
    $job = DaemonJob::query()->sole();
    expect($job->machine_id)->toBe($machine->id)
        ->and(mcpCall($workspace, $key, 'get_job', ['job' => $job->id])['text'])->toContain($job->id);

    // Another workspace's machine and job are nowhere to be found.
    $other = Workspace::factory()->onPlan(Plan::Pro)->create();
    $otherKey = mcpKey($other);
    expect(mcpCall($other, $otherKey, 'list_services', ['machine' => $machine->id])['error'])->toBeTrue()
        ->and(mcpCall($other, $otherKey, 'get_job', ['job' => $job->id])['error'])->toBeTrue();
});

test('stopping needs the name typed back, commands need two-factor, viewers only look', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();

    expect(mcpCall($workspace, $key, 'stop_service', ['machine' => 'web-1', 'service' => 'docker:aaa', 'confirm' => 'nope'])['error'])->toBeTrue()
        ->and(DaemonJob::query()->count())->toBe(0);
    expect(mcpCall($workspace, $key, 'run_command', ['machine' => 'web-1', 'service' => 'docker:aaa', 'command' => 'ls'])['text'])->toContain('two-factor');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $viewerKey = mcpKey($workspace, $viewer);
    $names = collect(mcpRequest($workspace, $viewerKey, 'tools/list')->json('result.tools'))->pluck('name');
    expect($names)->toContain('list_services')->not->toContain('restart_service')->not->toContain('deploy_from_git')
        ->and(mcpCall($workspace, $viewerKey, 'restart_service', ['machine' => 'web-1', 'service' => 'docker:aaa'])['error'])->toBeTrue()
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('the deploy catalog and history read without changing anything', function () {
    [$workspace, , $key] = serviceToolsSetup();

    expect(mcpCall($workspace, $key, 'list_templates', ['limit' => 5])['error'])->toBeFalse()
        ->and(mcpCall($workspace, $key, 'list_deploys', [])['error'])->toBeFalse()
        ->and(mcpCall($workspace, $key, 'get_app', ['machine' => 'web-1', 'app' => 'nope'])['error'])->toBeTrue()
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('removing an adopted container keeps its data unless both delete flags are set', function () {
    [$workspace, , $key] = serviceToolsSetup();
    $remove = fn (array $extra) => mcpCall($workspace, $key, 'remove_service', ['machine' => 'web-1', 'service' => 'docker:aaa', 'confirm' => 'shop', ...$extra]);
    $sent = fn () => json_decode(collect(Http::recorded())->last()[0]->body(), true)['command']['job'];

    $kept = $remove([]);
    expect($kept['error'])->toBeFalse()->and($kept['text'])->toContain('keeping its data')
        ->and($sent())->toMatchArray(['delete_data' => false, 'delete_adopted_volumes' => false]);

    expect($remove(['delete_data' => true])['text'])->toContain('delete_adopted_volumes')
        ->and(DaemonJob::query()->count())->toBe(1);

    expect($remove(['delete_data' => true, 'delete_adopted_volumes' => true])['error'])->toBeFalse()
        ->and($sent())->toMatchArray(['delete_data' => true, 'delete_adopted_volumes' => true]);

    // Only found, a systemd service: adopted first, the removal waiting on it, data never touched.
    expect(mcpCall($workspace, $key, 'remove_service', ['machine' => 'web-1', 'service' => 'nginx', 'confirm' => 'nginx.service', 'delete_data' => true])['text'])
        ->toContain('no volumes');
    expect(mcpCall($workspace, $key, 'remove_service', ['machine' => 'web-1', 'service' => 'nginx', 'confirm' => 'nginx.service'])['error'])->toBeFalse()
        ->and(DaemonJob::query()->where('kind', 'adopt')->sole()->then['job'])
        ->toBe(['type' => 'service_remove', 'service' => 'systemd:nginx.service', 'delete_data' => false, 'delete_adopted_volumes' => false]);
});

test('every tool that changes a service, group, job or deploy records it', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();
    $deploy = fn (string $state) => DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => $state, 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40)]],
    ]);
    $deploy('succeeded');
    $failed = $deploy('failed');
    $stuck = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'service_action', 'service' => 'docker:aaa', 'state' => 'running', 'log' => [],
        'command' => ['job' => ['type' => 'service_action', 'action' => 'restart']],
    ]);

    // Each tool, its arguments, and the entry it must leave. The test-only
    // MCP guard fails the request itself if a tool records nothing.
    $calls = [
        ['adopt_service', ['machine' => 'web-1', 'service' => 'nginx'], 'service.adopt'],
        ['create_service_group', ['machine' => 'web-1', 'name' => 'Web', 'services' => ['nginx']], 'service_group.create'],
        ['update_service_group', ['machine' => 'web-1', 'group' => 'Web', 'name' => 'Front'], 'service_group.update'],
        ['delete_service_group', ['machine' => 'web-1', 'group' => 'Front', 'confirm' => 'Front'], 'service_group.delete'],
        ['cancel_job', ['job' => $stuck->id], 'job.abandon'],
        ['retry_job', ['job' => $failed->id], 'service.deploy'],
        ['redeploy_app', ['machine' => 'web-1', 'app' => 'shop'], 'service.deploy'],
        ['deploy_template', ['machine' => 'web-1', 'template' => 'postgres', 'name' => 'db'], 'service.deploy'],
    ];
    foreach ($calls as [$tool, $arguments, $action]) {
        $before = Activity::query()->count();
        $result = mcpCall($workspace, $key, $tool, $arguments);
        expect($result['error'])->toBeFalse("{$tool}: {$result['text']}");
        $entry = Activity::query()->latest('id')->first();
        expect(Activity::query()->count())->toBe($before + 1, $tool)
            ->and($entry->action)->toBe($action, $tool)
            ->and($entry->source)->toBe('mcp', $tool)
            ->and($entry->meta)->toMatchArray(['via' => 'mcp', 'client' => 'Test key']);
    }
});

test('repair_service queues the repair for an adopted container', function () {
    [$workspace, , $key] = serviceToolsSetup();

    $repair = mcpCall($workspace, $key, 'repair_service', ['machine' => 'web-1', 'service' => 'docker:aaa']);
    expect($repair['error'])->toBeFalse()
        ->and(DaemonJob::query()->sole()->kind)->toBe('service_repair')
        ->and(Activity::query()->where('action', 'service.repair')->sole()->meta)->toMatchArray(['via' => 'mcp']);

    expect(mcpCall($workspace, $key, 'repair_service', ['machine' => 'web-1', 'service' => 'nginx'])['text'])->toContain('Adopt');
});

test('services are hidden from list_services and shown again over MCP', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();

    $hide = mcpCall($workspace, $key, 'hide_service', ['machine' => 'web-1', 'services' => ['nginx', 'shop']]);
    expect($hide['error'])->toBeFalse()
        ->and($hide['data']['changed'])->toBe(2)
        ->and(Activity::query()->where('action', 'service.hide')->sole()->meta)->toMatchArray(['via' => 'mcp']);

    $list = mcpCall($workspace, $key, 'list_services', ['machine' => 'web-1'])['data'];
    expect($list['total'])->toBe(0)->and($list['hidden'])->toBe(2);
    expect(mcpCall($workspace, $key, 'list_services', ['machine' => 'web-1', 'hidden' => true])['data']['total'])->toBe(2);

    expect(mcpCall($workspace, $key, 'unhide_service', ['machine' => 'web-1', 'service' => 'shop'])['data']['changed'])->toBe(1)
        ->and(mcpCall($workspace, $key, 'list_services', ['machine' => 'web-1'])['data']['services'][0]['key'])->toBe('docker:aaa');
});

test('a redeploy sends empty files and settings as maps, which the daemon can read', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();
    // As a kept deploy comes back from the database: its empty maps are [].
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://example.com/acme/shop.git', 'commit' => str_repeat('a', 40), 'files' => [], 'env' => [], 'domains' => [], 'port' => 3100]],
    ]);

    $result = mcpCall($workspace, $key, 'redeploy_app', ['machine' => 'web-1', 'app' => 'shop']);

    expect($result['error'])->toBeFalse($result['text']);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands')
        && str_contains($r->body(), '"files":{}')
        && str_contains($r->body(), '"env":{}')
        && str_contains($r->body(), '"domains":[]'));
});

test('an app built from Git moves to a new domain by deploying its live commit again', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://example.com/acme/shop.git', 'commit' => str_repeat('a', 40), 'files' => [], 'env' => ['APP_URL' => 'https://old.example.com'], 'domains' => ['old.example.com'], 'port' => 3100]],
    ]);

    expect(mcpCall($workspace, $key, 'get_app', ['machine' => 'web-1', 'app' => 'shop'])['text'])->toContain('"canChangeDomain":true');

    $result = mcpCall($workspace, $key, 'set_app_domain', ['machine' => 'web-1', 'app' => 'shop', 'domain' => 'shop.example.com']);

    expect($result['error'])->toBeFalse($result['text']);
    $job = DaemonJob::query()->latest('created_at')->where('kind', 'deploy')->where('state', 'queued')->sole();
    expect($job->command['job'])->toMatchArray([
        'commit' => str_repeat('a', 40),
        'domains' => ['shop.example.com'],
        'env' => ['APP_URL' => 'https://shop.example.com'],
    ]);
});

test('update_app_settings changes only the named settings and deploys the live commit again', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://example.com/acme/shop.git', 'commit' => str_repeat('a', 40), 'files' => [], 'env' => ['TOKEN_SHA' => 'old', 'MAIL' => 'log', 'OLD' => 'x'], 'domains' => ['shop.example.com'], 'port' => 3100]],
    ]);

    $result = mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'set' => ['TOKEN_SHA' => 'hash-9f3c', 'MAIL' => 'log'], 'remove' => ['OLD']]);

    expect($result['error'])->toBeFalse($result['text'])
        ->and($result['data'])->toMatchArray(['replaced' => ['TOKEN_SHA'], 'added' => [], 'removed' => ['OLD'], 'settings' => ['TOKEN_SHA', 'MAIL']])
        ->and($result['text'])->not->toContain('hash-9f3c');
    $job = DaemonJob::query()->where('state', 'queued')->sole();
    expect($job->command['job'])->toMatchArray([
        'commit' => str_repeat('a', 40),
        'domains' => ['shop.example.com'],
        'port' => 3100,
        'env' => ['TOKEN_SHA' => 'hash-9f3c', 'MAIL' => 'log'],
    ]);

    foreach ([
        [['set' => ['NEXT_PUBLIC_URL' => 'x']], 'read while the app is built'],
        [['remove' => ['NOPE']], 'isn’t one of shop’s settings'],
        [['set' => ['1BAD' => 'x']], 'isn’t a setting name'],
        [[], 'Say which settings to change'],
    ] as [$change, $message]) {
        $refused = mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', ...$change]);
        expect($refused['error'])->toBeTrue()->and($refused['text'])->toContain($message);
    }
});

test('deploy_template builds a PHP app on the version asked for, and only PHP templates take one', function () {
    [$workspace, , $key] = serviceToolsSetup();
    $app = ['machine' => 'web-1', 'template' => 'laravel', 'name' => 'old-app', 'repo' => 'https://github.com/acme/old-app.git', 'ref' => str_repeat('a', 40), 'health_path' => '/'];

    $result = mcpCall($workspace, $key, 'deploy_template', [...$app, 'php' => '8.1']);
    expect($result['error'])->toBeFalse($result['text']);
    $job = DaemonJob::query()->where('service', 'old-app')->sole()->command['job'];
    expect($job['files']['.serveros/Dockerfile'])->toContain('FROM serversideup/php:8.1-fpm-nginx')
        ->and($job['health']['path'] ?? $job['health'])->not->toBeNull();

    expect(mcpCall($workspace, $key, 'deploy_template', [...$app, 'name' => 'other', 'php' => '5.6'])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'deploy_template', ['machine' => 'web-1', 'template' => 'postgres', 'name' => 'db', 'php' => '8.1'])['text'])->toContain('doesn’t run on PHP');
});

test('get_connection gives an app the database’s settings at the Docker bridge, only to people who manage machines', function () {
    [$workspace, $machine, $key] = serviceToolsSetup();
    $machine->forceFill(['facts' => ['interfaces' => [['name' => 'docker0', 'addresses' => ['172.18.0.1/16']]]]])->save();
    $template = app(ServiceTemplateCatalog::class)->find('postgres');
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'postgres', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'postgres', 'commit' => sha1("postgres\n".json_encode($template->files)), 'port' => 5432,
            'env' => ['POSTGRES_USER' => 'app', 'POSTGRES_PASSWORD' => 's3cret', 'POSTGRES_DB' => 'shop']]]]);

    $result = mcpCall($workspace, $key, 'get_connection', ['machine' => 'web-1', 'service' => 'postgres']);
    expect($result['error'])->toBeFalse($result['text'])
        ->and($result['data']['host'])->toBe('172.18.0.1')
        ->and($result['data']['env'])->toMatchArray(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '172.18.0.1', 'DB_PORT' => '5432', 'DB_DATABASE' => 'shop', 'DB_USERNAME' => 'app', 'DB_PASSWORD' => 's3cret']);

    expect(mcpCall($workspace, $key, 'get_connection', ['machine' => 'web-1', 'service' => 'shop'])['text'])->toContain('isn’t a database or cache ServerOS deployed')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'get_connection', ['machine' => 'web-1', 'service' => 'postgres'])['error'])->toBeTrue();
});
