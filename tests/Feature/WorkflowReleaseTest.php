<?php

use App\Enums\Plan;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Workflows\ReleaseSpec;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github_app.webhook_secret', 'hook-secret');
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop/commits/main' => Http::response(['sha' => str_repeat('b', 40)]),
        '*' => Http::response('ok'),
    ]);
    Notification::fake();
    $this->withoutDefer();
});

/**
 * A workspace with a machine running a Laravel app under PHP-FPM, the
 * GitHub App on acme, and a "Push to GitHub" workflow releasing it.
 *
 * @return array{0: Workspace, 1: Machine, 2: Workflow}
 */
function releaseFlow(array $release = [], string $branch = 'main'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'acme', 'account_type' => 'Organization']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'systemd:php8.4-fpm.service', 'name' => 'php8.4-fpm', 'kind' => 'app', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    $graph = WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => 'git.push', 'repo' => 'acme/shop', 'branch' => $branch]],
        ['id' => 'r', 'kind' => 'release', 'x' => 0, 'y' => 200, 'title' => '', 'description' => '', 'rules' => [
            'machine_id' => $machine->id,
            'service' => 'systemd:php8.4-fpm.service',
            'reload' => 'systemd:php8.4-fpm.service',
            'path' => '/var/www/shop',
            'user' => 'www-data',
            'repo' => 'acme/shop',
            'branch' => $branch,
            'steps' => json_encode(ReleaseSpec::LARAVEL_STEPS),
            'health_url' => 'https://shop.example/up',
            ...$release,
        ]],
    ], 'edges' => [['id' => 'e', 'source' => 't', 'port' => 'out', 'target' => 'r']]]);

    $workflow = $workspace->workflows()->create([
        'name' => 'Ship shop', 'graph' => $graph, 'enabled' => true, 'run_as' => $workspace->owner_id, 'trigger' => 'git.push',
    ]);

    return [$workspace, $machine, $workflow];
}

function push(array $overrides = [], string $secret = 'hook-secret'): TestResponse
{
    $body = json_encode(array_replace_recursive([
        'ref' => 'refs/heads/main',
        'after' => str_repeat('a', 40),
        'deleted' => false,
        'repository' => ['full_name' => 'acme/shop'],
        'installation' => ['id' => 42],
        'head_commit' => ['message' => "Fix the cart\n\nLonger body", 'url' => 'https://github.com/acme/shop/commit/aaa'],
        'pusher' => ['name' => 'liam'],
    ], $overrides));

    return test()->call('POST', '/api/github/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

test('a push releases the pushed commit where the app runs', function () {
    [$workspace, $machine] = releaseFlow();

    push()->assertStatus(202)->assertJson(['started' => 1]);

    $run = WorkflowRun::query()->sole();
    $job = DaemonJob::query()->sole();
    $spec = $job->command['job'];

    expect($run->trigger['summary'])->toBe('Push to main of acme/shop: aaaaaaa Fix the cart')
        ->and($job->kind)->toBe('release')
        ->and($job->service)->toBe('systemd:php8.4-fpm.service')
        ->and($spec['commit'])->toBe(str_repeat('a', 40))
        ->and($spec['repo'])->toBe('https://github.com/acme/shop.git')
        ->and($spec['path'])->toBe('/var/www/shop')
        ->and($spec['user'])->toBe('www-data')
        ->and($spec['reload'])->toBe([['service' => 'systemd:php8.4-fpm.service', 'action' => 'reload']])
        ->and($spec['health_url'])->toBe('https://shop.example/up')
        ->and(collect($spec['steps'])->pluck('name')->all())->toBe(['install', 'storage:link', 'ci', 'migrate', 'optimize', 'queue:restart'])
        ->and(collect($spec['steps'])->firstWhere('name', 'migrate')['on_rollback'])->toBeFalse();

    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'succeeded', 'log' => [], 'result' => ['commit' => str_repeat('a', 40)]]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    expect($run->refresh()->state)->toBe('succeeded')
        ->and($run->steps['r']['message'])->toBe('Released aaaaaaa');
});

test('pushes need a good signature and only start workflows on that repository and branch', function () {
    releaseFlow();

    push([], 'wrong')->assertUnauthorized();
    push(['ref' => 'refs/heads/feature'])->assertJson(['started' => 0]);
    push(['repository' => ['full_name' => 'acme/other']])->assertJson(['started' => 0]);
    push(['installation' => ['id' => 7]])->assertJson(['started' => 0]);
    push(['deleted' => true, 'after' => str_repeat('0', 40)])->assertJson(['ignored' => true]);

    expect(DaemonJob::query()->count())->toBe(0);
});

test('run by hand, a release takes the branch’s latest commit', function () {
    [$workspace, , $workflow] = releaseFlow();

    $this->actingAs($workspace->owner)->post('https://'.$workspace->host()."/workflows/{$workflow->id}/run")->assertRedirect();

    expect(DaemonJob::query()->sole()->command['job']['commit'])->toBe(str_repeat('b', 40));
});

test('a release needs a managed service, a folder, a non-root user and two-factor', function () {
    [$workspace, $machine, $workflow] = releaseFlow(['user' => 'root', 'path' => 'relative']);
    $problems = WorkflowSteps::problems($workflow->graph, $workspace);
    expect($problems['r'])->toBe('Enter the app’s folder, like /var/www/app.');

    [$workspace, , $workflow] = releaseFlow(['user' => 'root']);
    expect(WorkflowSteps::problems($workflow->graph, $workspace)['r'])->toContain('Never root');

    [$workspace, , $workflow] = releaseFlow(['service' => 'systemd:nginx.service']);
    expect(WorkflowSteps::problems($workflow->graph, $workspace)['r'])->toContain('isn’t managed');

    [$workspace, , $workflow] = releaseFlow();
    Workflow::query()->whereKeyNot($workflow->id)->delete();
    expect(WorkflowSteps::problems($workflow->graph, $workspace))->toBe([]);
    $workspace->owner->forceFill(['two_factor_confirmed_at' => null])->save();
    push();
    expect(WorkflowRun::query()->latest('id')->first()->steps['r']['message'])->toContain('two-factor')
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('the Laravel template releases on push', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/workflows', ['template' => 'laravel-push'])->assertRedirect();
    $workflow = Workflow::query()->sole();

    expect($workflow->trigger)->toBe('git.push')
        ->and(collect($workflow->graph['nodes'])->pluck('kind')->all())->toBe(['trigger', 'release', 'alert', 'alert']);
});

test('atomic releases share .env and storage, restart what runs the app, and can be rolled back', function () {
    [$workspace, $machine, $workflow] = releaseFlow([
        'mode' => 'atomic', 'shared' => ".env\nstorage", 'trim' => 'node_modules', 'keep' => 4,
        'restart' => 'systemd:php8.4-fpm.service',
    ]);

    push()->assertStatus(202);
    $job = DaemonJob::query()->sole();
    $spec = $job->command['job'];

    expect($spec['mode'])->toBe('atomic')
        ->and($spec['shared'])->toBe(['.env', 'storage'])
        ->and($spec['trim'])->toBe(['node_modules'])
        ->and($spec['keep'])->toBe(4)
        ->and($spec['reload'])->toBe([
            ['service' => 'systemd:php8.4-fpm.service', 'action' => 'reload'],
            ['service' => 'systemd:php8.4-fpm.service', 'action' => 'restart'],
        ]);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'succeeded', 'log' => [], 'result' => [
            'commit' => str_repeat('a', 40), 'previous' => str_repeat('c', 40), 'release' => '/var/www/shop-releases/1-aaaaaaaaaaaa',
        ]]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $run = WorkflowRun::query()->sole();
    expect($run->steps['r']['rollbackTo'])->toBe(str_repeat('c', 40));

    $url = 'https://'.$workspace->host()."/workflows/{$workflow->id}/runs/{$run->id}/rollback/r";
    $this->actingAs($workspace->owner)->post($url)->assertRedirect();

    $back = DaemonJob::query()->latest('created_at')->where('id', '!=', $job->id)->sole();
    expect($back->kind)->toBe('release')
        ->and($back->command['job']['commit'])->toBe(str_repeat('c', 40))
        ->and($back->command['job']['mode'])->toBe('atomic')
        ->and($run->refresh()->steps['r']['rolledBack'])->toBeTrue();

    // Once is enough.
    $this->actingAs($workspace->owner)->post($url)->assertNotFound();
});

test('atomic settings must stay inside the app', function () {
    [$workspace, , $workflow] = releaseFlow(['mode' => 'atomic', 'shared' => "../etc\nstorage"]);
    expect(WorkflowSteps::problems($workflow->graph, $workspace)['r'])->toContain('inside the app');

    [$workspace, , $workflow] = releaseFlow(['mode' => 'atomic', 'shared' => '.git/config']);
    expect(WorkflowSteps::problems($workflow->graph, $workspace)['r'])->toContain('inside the app');
});

test('a push redeploys an app built from that repository at the pushed commit', function () {
    [$workspace, $machine] = releaseFlow();
    $workspace->workflows()->delete();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('c', 40), 'files' => [], 'env' => ['APP_KEY' => 'k'], 'domains' => ['shop.example'], 'port' => 3100]],
    ]);
    $graph = WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => 'git.push', 'repo' => 'acme/shop', 'branch' => 'main']],
        ['id' => 'd', 'kind' => 'redeploy', 'x' => 0, 'y' => 200, 'title' => '', 'description' => '', 'rules' => ['machine_id' => $machine->id, 'service' => 'shop']],
    ], 'edges' => [['id' => 'e', 'source' => 't', 'port' => 'out', 'target' => 'd']]]);
    $workspace->workflows()->create(['name' => 'Ship shop', 'graph' => $graph, 'enabled' => true, 'run_as' => $workspace->owner_id, 'trigger' => 'git.push']);

    push()->assertStatus(202)->assertJson(['started' => 1]);

    $job = DaemonJob::query()->where('state', 'queued')->sole();
    expect($job->command['job'])->toMatchArray([
        'commit' => str_repeat('a', 40),
        'repo' => 'https://github.com/acme/shop',
        'domains' => ['shop.example'],
        'env' => ['APP_KEY' => 'k'],
        'port' => 3100,
    ])->and(WorkflowRun::query()->sole()->steps['d']['message'])->toBe('Deploying aaaaaaa of shop on web-1');
});

test('only a push to the app’s own repository changes what a redeploy builds', function () {
    $spec = ['repo' => 'https://github.com/Acme/Shop.git', 'commit' => str_repeat('c', 40)];

    expect(WorkflowEngine::pushedCommit($spec, ['repo' => 'acme/shop', 'commit' => str_repeat('A', 40)]))->toBe(str_repeat('a', 40))
        ->and(WorkflowEngine::pushedCommit($spec, ['repo' => 'acme/other', 'commit' => str_repeat('a', 40)]))->toBeNull()
        ->and(WorkflowEngine::pushedCommit($spec, ['repo' => 'acme/shop', 'commit' => str_repeat('c', 40)]))->toBeNull()
        ->and(WorkflowEngine::pushedCommit($spec, []))->toBeNull()
        ->and(WorkflowEngine::pushedCommit(['repo' => 'https://serveros.com/api/deploy-sources/x', 'commit' => 'x'], ['repo' => 'acme/shop', 'commit' => str_repeat('a', 40)]))->toBeNull();
});
