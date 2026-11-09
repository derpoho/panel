<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Projects\Connections;
use App\Support\Projects\Placement;
use App\Support\Projects\Previews;
use App\Support\Projects\ProjectData;
use App\Support\Projects\ProjectLinks;
use App\Support\Projects\ProjectNetwork;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Two machines: web-1 runs the app "shop", db-1 runs a Postgres deployed
 * from its template as "shop-db".
 *
 * @return array{0: Workspace, 1: Machine, 2: Machine, 3: string}
 */
function projectSetup(bool $sameMachine = false): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $web = projectMachine($workspace, 'web-1', '203.0.113.10', [
        ['key' => 'docker:shop', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'running', 'details' => []],
        ...($sameMachine ? [['key' => 'docker:pg', 'name' => 'serveros-shop-db-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db']]] : []),
    ]);
    $db = $sameMachine ? $web : projectMachine($workspace, 'db-1', '203.0.113.20', [
        ['key' => 'docker:pg', 'name' => 'serveros-shop-db-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db']],
    ]);

    projectDeploy($web, 'shop', ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'env' => ['APP_ENV' => 'production'], 'port' => 3000]);
    $template = app(ServiceTemplateCatalog::class)->find('postgres');
    $spec = (new TemplateDeployment($template, $db, ['service' => 'shop-db', 'port' => 5432, 'env' => ['POSTGRES_PASSWORD' => 's3cret']]))->job();
    projectDeploy($db, 'shop-db', json_decode(json_encode($spec), true));

    return [$workspace, $web, $db, 'https://'.$workspace->host()];
}

/** @param  list<array<string, mixed>>  $services */
function projectMachine(Workspace $workspace, string $name, string $ip, array $services): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'ip_address' => $ip]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => $services], 'facts' => ['docker_version' => '27.3.1', 'interfaces' => [['name' => 'docker0', 'addresses' => ['172.17.0.1/16']]]]])->save();

    return $machine;
}

/** @param  array<string, mixed>  $job */
function projectDeploy(Machine $machine, string $service, array $job): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $machine->workspace->owner_id,
        'kind' => 'deploy',
        'service' => $service,
        'state' => 'succeeded',
        'command' => ['job' => $job],
        'result' => [],
    ]);
}

/** @return list<array{machine: string, job: array<string, mixed>}> */
function projectCommands(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (GatewayRequest $r) => ['machine' => explode('/', $r->url())[4] ?? '', 'job' => json_decode($r->body(), true)['command']['job']])
        ->values()
        ->all();
}

/** The daemon answers a network job: up, with this public key. */
function networkUp(Machine $machine, string $key): void
{
    $job = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'network')->latest()->first();
    $job->forceFill(['state' => 'succeeded', 'result' => ['public_key' => $key]])->save();
    app(ProjectNetwork::class)->jobFinished($job->fresh());
}

test('the add menu installs each kind from a template a project knows how to connect', function () {
    foreach (Connections::DEFAULTS as $kind => $slug) {
        expect(app(ServiceTemplateCatalog::class)->find($slug))->not->toBeNull()
            ->and(Connections::TEMPLATES[$slug]['kind'])->toBe($kind);
    }
});

test('a postgres deploy is recognised by its template and gives an app its settings', function () {
    [, , $db] = projectSetup();
    $deploy = DaemonJob::query()->where('service', 'shop-db')->sole();
    $connections = app(Connections::class);

    expect($connections->templateOf($deploy))->toBe('postgres');
    $env = $connections->env('postgres', $deploy->command['job'], '10.64.1.3');
    expect($env['DATABASE_URL'])->toBe('postgres://app:s3cret@10.64.1.3:5432/app')
        ->and($env['DB_HOST'])->toBe('10.64.1.3')
        ->and($env['DB_CONNECTION'])->toBe('pgsql');
});

test('across two machines the app is connected over the project network once both are up', function () {
    [$workspace, $web, $db, $base] = projectSetup();
    $owner = $workspace->owner;

    $this->actingAs($owner)->post($base.'/projects', ['name' => 'Shop'])->assertRedirect();
    $project = Project::query()->sole();
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => 'shop'])->assertRedirect();
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $db->id, 'name' => 'shop-db'])->assertRedirect();

    $nodes = $project->nodes()->get()->keyBy('name');
    expect($nodes['shop']->kind)->toBe('app')
        ->and($nodes['shop-db']->kind)->toBe('database')
        ->and($nodes['shop-db']->template)->toBe('postgres');

    $this->actingAs($owner)->post($base."/projects/{$project->uid}/links", ['app' => $nodes['shop']->id, 'resource' => $nodes['shop-db']->id])->assertRedirect();
    $link = ProjectLink::query()->sole();
    expect($link->state)->toBe('pending');

    // Each machine was told its own address and port; db-1 offers Postgres.
    $prefix = $project->prefix();
    $network = collect(projectCommands())->filter(fn ($c) => $c['job']['type'] === 'network');
    $dbJob = $network->last(fn ($c) => $c['machine'] === $db->uid)['job'];
    expect($dbJob['network'])->toBe($project->uid)
        ->and($dbJob['address'])->toBe("{$prefix}.3/24")
        ->and($dbJob['listen_port'])->toBe(51820)
        ->and($dbJob['expose'])->toBe([['port' => 5432, 'target' => '127.0.0.1:5432']]);

    // Both come up: each learns the other, then the app gets its settings.
    networkUp($web, 'web-public-key');
    networkUp($db, 'db-public-key');

    $dbPeers = collect(projectCommands())->last(fn ($c) => $c['machine'] === $db->uid && $c['job']['type'] === 'network')['job']['peers'];
    expect($dbPeers)->toBe([['public_key' => 'web-public-key', 'address' => "{$prefix}.2/32", 'endpoint' => '203.0.113.10:51820']]);

    $redeploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy')['job'];
    expect($redeploy['service'])->toBe('shop')
        ->and($redeploy['env']['DATABASE_URL'])->toBe("postgres://app:s3cret@{$prefix}.3:5432/app")
        ->and($redeploy['env']['APP_ENV'])->toBe('production');
    expect($link->fresh()->state)->toBe('applied');
});

test('on one machine the app is connected straight away through the docker bridge', function () {
    [$workspace, $web, , $base] = projectSetup(sameMachine: true);
    $owner = $workspace->owner;
    $project = $workspace->projects()->create(['name' => 'Shop']);

    foreach (['shop', 'shop-db'] as $name) {
        $this->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => $name])->assertRedirect();
    }
    $nodes = $project->nodes()->get()->keyBy('name');
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/links", ['app' => $nodes['shop']->id, 'resource' => $nodes['shop-db']->id])->assertRedirect();

    $redeploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy')['job'];
    expect($redeploy['env']['DATABASE_URL'])->toBe('postgres://app:s3cret@172.17.0.1:5432/app')
        ->and(ProjectLink::query()->sole()->state)->toBe('applied');

    // Disconnecting takes exactly those settings out again.
    $this->actingAs($owner)->delete($base."/projects/{$project->uid}/links/".ProjectLink::query()->sole()->id)->assertRedirect();
    projectDeploy($web, 'shop', $redeploy);
    expect(ProjectLink::query()->count())->toBe(0);
});

test('adding a database from the toolbar deploys it and connects the app once it is up', function () {
    [$workspace, $web, , $base] = projectSetup(sameMachine: true);
    $owner = $workspace->owner;
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => 'shop'])->assertRedirect();

    $this->actingAs($owner)->post($base."/projects/{$project->uid}/resources", ['kind' => 'cache', 'machine' => $web->id])->assertRedirect()->assertSessionHasNoErrors();

    $node = $project->nodes()->where('kind', 'cache')->sole();
    expect($node->template)->toBe('valkey')
        ->and(ProjectLink::query()->sole()->resource_node_id)->toBe($node->id);

    // The cache's deploy finishes: the app is pointed at it.
    $deploy = DaemonJob::query()->where('service', $node->name)->sole();
    $deploy->forceFill(['state' => 'succeeded'])->save();
    app(ProjectLinks::class)->deployFinished($deploy->fresh());

    $redeploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    expect($redeploy['env']['REDIS_HOST'])->toBe('172.17.0.1')
        ->and($redeploy['env']['REDIS_PORT'])->toBe((string) $deploy->command['job']['port']);
});

test('people outside the workspace can’t see or change its projects', function () {
    [$workspace, , , $base] = projectSetup();
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $other = Workspace::factory()->create();

    $this->actingAs($other->owner)->get($base."/projects/{$project->uid}")->assertForbidden();
});

/**
 * A project in Production with the app on web-1 using a Postgres on db-1,
 * connected; plus a third machine, stage-1, for Staging.
 *
 * @return array{0: Workspace, 1: Project, 2: Machine, 3: Machine, 4: Machine, 5: string}
 */
function projectWithProduction(): array
{
    [$workspace, $web, $db, $base] = projectSetup();
    $stage = projectMachine($workspace, 'stage-1', '203.0.113.30', []);
    foreach ([$web, $db, $stage] as $machine) {
        $machine->forceFill(['daemon_version' => '0.1.1-pre.40'])->save();
    }
    $owner = $workspace->owner;
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $project->production();
    test()->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => 'shop'])->assertRedirect();
    test()->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $db->id, 'name' => 'shop-db'])->assertRedirect();
    $nodes = $project->nodes()->get()->keyBy('name');
    test()->actingAs($owner)->post($base."/projects/{$project->uid}/links", ['app' => $nodes['shop']->id, 'resource' => $nodes['shop-db']->id])->assertRedirect();
    networkUp($web, 'web-public-key');
    networkUp($db, 'db-public-key');
    // The app's redeploy with its settings finished.
    $redeploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    projectDeploy($web, 'shop', [...$redeploy, 'domains' => ['shop.example.com']]);

    return [$workspace, $project->fresh(), $web, $db, $stage, $base];
}

test('a staging copy gets its own database, the app without its domain, and the same connection', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $production = $project->production();

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging',
        'source' => $production->id,
        'machines' => [$web->id => $stage->id, $db->id => $stage->id],
        'copy_data' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $staging = $project->environments()->where('name', 'Staging')->sole();
    expect($staging->source_id)->toBe($production->id);
    $copies = $staging->nodes()->get()->keyBy('kind');
    expect($copies)->toHaveCount(2)
        ->and($copies['app']->machine_id)->toBe($stage->id)
        ->and($copies['app']->name)->toBe('shop-staging')
        ->and($copies['database']->name)->toBe('shop-db-staging')
        ->and($copies['database']->data_state)->toBe('waiting');

    $deploys = collect(projectCommands())->filter(fn ($c) => $c['job']['type'] === 'deploy' && $c['machine'] === $stage->uid)->keyBy(fn ($c) => $c['job']['service']);
    // A new Postgres with a password of its own.
    expect($deploys['shop-db-staging']['job']['env']['POSTGRES_PASSWORD'])->not->toBe('s3cret');
    // The same code, no domain, and none of Production's connection settings.
    expect($deploys['shop-staging']['job']['commit'])->toBe(str_repeat('a', 40))
        ->and($deploys['shop-staging']['job']['domains'])->toBe([])
        ->and($deploys['shop-staging']['job']['env'])->toBe(['APP_ENV' => 'production']);

    $link = ProjectLink::query()->where('app_node_id', $copies['app']->id)->sole();
    expect($link->resource_node_id)->toBe($copies['database']->id)->and($link->state)->toBe('pending');

    // Production's Postgres is now offered on db-1's network address, for the copy.
    $dbNetwork = collect(projectCommands())->last(fn ($c) => $c['machine'] === $db->uid && $c['job']['type'] === 'network')['job'];
    expect($dbNetwork['expose'])->toBe([['port' => 5432, 'target' => '127.0.0.1:5432']]);
});

test('the copy is filled from production over the network, with the password kept out of the command', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $project->production()->id,
        'machines' => [$web->id => $stage->id, $db->id => $stage->id], 'copy_data' => true,
    ])->assertRedirect();
    $copy = ProjectNode::query()->where('name', 'shop-db-staging')->sole();

    // Its deploy finishes and the machine reports its container; stage-1 joins.
    $deploy = DaemonJob::query()->where('service', 'shop-db-staging')->sole();
    $deploy->forceFill(['state' => 'succeeded'])->save();
    $stage->forceFill(['inventory' => ['services' => [['key' => 'docker:abc123', 'name' => 'serveros-shop-db-staging-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db-staging']]]]])->save();
    app(ProjectData::class)->deployFinished($deploy->fresh());
    expect($copy->fresh()->data_state)->toBe('waiting');

    networkUp($stage, 'stage-public-key');
    app(ProjectData::class)->tick(CarbonImmutable::now());

    $exec = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'service_exec')['job'];
    $prefix = $project->prefix();
    expect($exec['service'])->toBe('docker:abc123')
        ->and($exec['env'])->toMatchArray(['SOURCE_HOST' => "{$prefix}.3", 'SOURCE_PORT' => '5432', 'SOURCE_PASSWORD' => 's3cret', 'SOURCE_DB' => 'app'])
        ->and($exec['command'])->not->toContain('s3cret')
        ->and($exec['command'])->toContain('pg_dump');
    expect($copy->fresh()->data_state)->toBe('copying');

    $job = DaemonJob::query()->where('kind', 'service_exec')->sole();
    $job->forceFill(['state' => 'succeeded', 'result' => ['exit_code' => 0]])->save();
    app(ProjectData::class)->jobFinished($job->fresh());
    expect($copy->fresh()->data_state)->toBe('copied')->and($copy->fresh()->data_at)->not->toBeNull();
});

test('production is never written into, and a copy that exits badly is marked failed', function () {
    [$workspace, $project] = projectWithProduction();
    $original = $project->nodes()->where('name', 'shop-db')->sole();
    app(ProjectData::class)->start($original, $workspace->owner);
    expect($original->fresh()->data_state)->toBeNull()
        ->and(DaemonJob::query()->where('kind', 'service_exec')->exists())->toBeFalse();

    $this->actingAs($workspace->owner)->post('https://'.$workspace->host()."/projects/{$project->uid}/environments/{$project->production()->id}/refresh")
        ->assertSessionHasErrors('environment');
});

test('promoting deploys production at the code staging runs, keeping production’s settings and domain', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $production = $project->production();
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $production->id,
        'machines' => [$web->id => $stage->id, $db->id => $stage->id],
    ])->assertRedirect();
    $staging = $project->environments()->where('name', 'Staging')->sole();
    projectDeploy($stage, 'shop-staging', ['type' => 'deploy', 'service' => 'shop-staging', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('b', 40), 'env' => ['APP_ENV' => 'staging', 'NEW_FLAG' => '1'], 'domains' => [], 'port' => 3000]);

    $this->actingAs($workspace->owner)->get($base."/projects/{$project->uid}?from={$staging->id}&to={$production->id}")
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('promotion', fn (Assert $reload) => $reload
            ->where('promotion.0.app', 'shop')
            ->where('promotion.0.from', 'aaaaaaa')
            ->where('promotion.0.to', 'bbbbbbb')
            ->where('promotion.0.missing', ['NEW_FLAG'])
            ->where('promotion.0.ready', true)));

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/promote", ['from' => $staging->id, 'to' => $production->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $deploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    expect($deploy['commit'])->toBe(str_repeat('b', 40))
        ->and($deploy['domains'])->toBe(['shop.example.com'])
        ->and($deploy['env']['APP_ENV'])->toBe('production')
        ->and($deploy['env'])->not->toHaveKey('NEW_FLAG')
        ->and($deploy['env']['DATABASE_URL'])->toContain('s3cret');
});

test('an app’s settings change from the canvas, but not the ones a connection writes', function () {
    [$workspace, $project, , , , $base] = projectWithProduction();
    $app = $project->nodes()->where('name', 'shop')->sole();

    $this->actingAs($workspace->owner)->get($base."/projects/{$project->uid}?app={$app->id}")
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('settings', fn (Assert $reload) => $reload
            ->where('settings.names.0', ['name' => 'APP_ENV', 'from' => null, 'build' => false])
            ->where('settings.names.1', ['name' => 'DATABASE_URL', 'from' => 'shop-db', 'build' => false])));

    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/nodes/{$app->id}/settings", ['set' => ['DATABASE_URL' => 'x']])
        ->assertSessionHasErrors('settings');

    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/nodes/{$app->id}/settings", ['set' => ['MAIL_HOST' => 'smtp.example.com'], 'remove' => ['APP_ENV']])
        ->assertRedirect()->assertSessionHasNoErrors();
    $deploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    expect($deploy['env']['MAIL_HOST'])->toBe('smtp.example.com')
        ->and($deploy['env'])->not->toHaveKey('APP_ENV')
        ->and($deploy['domains'])->toBe(['shop.example.com']);
});

test('an environment set to refresh nightly is copied again once a day', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $project->production()->id,
        'machines' => [$web->id => $stage->id, $db->id => $stage->id],
    ])->assertRedirect();
    $staging = $project->environments()->where('name', 'Staging')->sole();
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/environments/{$staging->id}", ['refresh' => 'daily'])->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/environments/{$project->production()->id}", ['refresh' => 'daily'])->assertSessionHasErrors('refresh');

    $now = CarbonImmutable::now();
    expect($staging->fresh()->refreshDue($now))->toBeTrue();
    app(ProjectData::class)->tick($now);
    expect($staging->fresh()->refreshed_at)->not->toBeNull()
        ->and(ProjectNode::query()->where('name', 'shop-db-staging')->value('data_state'))->toBe('waiting')
        ->and($staging->fresh()->refreshDue($now->addHours(12)))->toBeFalse()
        ->and($staging->fresh()->refreshDue($now->addHours(24)))->toBeTrue();

    // Deleting it takes its apps and services off the canvas.
    $this->actingAs($workspace->owner)->delete($base."/projects/{$project->uid}/environments/{$staging->id}")->assertRedirect();
    expect(ProjectNode::query()->where('name', 'like', '%-staging')->exists())->toBeFalse();
    $this->actingAs($workspace->owner)->delete($base."/projects/{$project->uid}/environments/{$project->production()->id}")->assertSessionHasErrors('environment');
});

/** Make Staging from Production, everything on stage-1. */
function makeStaging(Workspace $workspace, Project $project, Machine $web, Machine $db, Machine $stage, string $base, bool $copyData = false): ProjectEnvironment
{
    test()->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $project->production()->id,
        'machines' => [$web->id => $stage->id, $db->id => $stage->id], 'copy_data' => $copyData,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return $project->environments()->where('name', 'Staging')->sole();
}

test('an app only connects to services in its own environment', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);
    $prodDb = $project->nodes()->where('name', 'shop-db')->sole();
    $stagingApp = $staging->nodes()->where('kind', 'app')->sole();

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/links", ['app' => $stagingApp->id, 'resource' => $prodDb->id])
        ->assertSessionHasErrors('resource');
    expect(ProjectLink::query()->where('app_node_id', $stagingApp->id)->pluck('resource_node_id')->all())
        ->toBe([$staging->nodes()->where('kind', 'database')->value('id')]);
});

test('a new database from the menu in Staging connects Staging’s apps only', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/resources", ['kind' => 'cache', 'machine' => $stage->id, 'environment' => $staging->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $cache = $project->nodes()->where('kind', 'cache')->sole();
    expect($cache->environment_id)->toBe($staging->id)
        ->and(ProjectLink::query()->where('resource_node_id', $cache->id)->pluck('app_node_id')->all())
        ->toBe([$staging->nodes()->where('kind', 'app')->value('id')]);
});

test('an environment isn’t made on a machine without Docker', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $stage->forceFill(['facts' => ['interfaces' => []], 'inventory' => ['services' => []]])->save();

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $project->production()->id, 'machines' => [$web->id => $stage->id, $db->id => $stage->id],
    ])->assertSessionHasErrors('form');
    expect($project->environments()->count())->toBe(1)
        ->and(DaemonJob::query()->where('machine_id', $stage->id)->where('kind', 'deploy')->exists())->toBeFalse();
});

test('environment names are unique in a project, and Production can be renamed', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    makeStaging($workspace, $project, $web, $db, $stage, $base);

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", ['name' => 'Staging', 'source' => $project->production()->id])
        ->assertSessionHasErrors('name');
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/environments/{$project->production()->id}", ['name' => 'Live'])
        ->assertSessionHasNoErrors();
    expect($project->production()->name)->toBe('Live');
});

test('on the same machine a copy is filled through Docker’s bridge without waiting for the network', function () {
    [$workspace, $web, , $base] = projectSetup(sameMachine: true);
    $web->forceFill(['daemon_version' => '0.1.1-pre.40'])->save();
    $project = $workspace->projects()->create(['name' => 'Shop']);
    foreach (['shop', 'shop-db'] as $name) {
        $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => $name])->assertRedirect();
    }
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $project->production()->id, 'copy_data' => true,
    ])->assertSessionHasNoErrors();

    $copy = ProjectNode::query()->where('name', 'shop-db-staging')->sole();
    expect($copy->machine_id)->toBe($web->id);
    DaemonJob::query()->where('service', 'shop-db-staging')->sole()->forceFill(['state' => 'succeeded'])->save();
    $web->forceFill(['inventory' => ['services' => [...$web->inventory['services'], ['key' => 'docker:copy1', 'name' => 'serveros-shop-db-staging-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db-staging']]]]])->save();
    app(ProjectData::class)->start($copy->fresh(), $workspace->owner);

    $exec = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'service_exec')['job'];
    expect($exec['env']['SOURCE_HOST'])->toBe('172.17.0.1')
        ->and($exec['service'])->toBe('docker:copy1')
        ->and($exec['timeout_secs'])->toBe(3600);
});

test('a copy on an old daemon, or one that exits badly, says why', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    makeStaging($workspace, $project, $web, $db, $stage, $base, copyData: true);
    $copy = ProjectNode::query()->where('name', 'shop-db-staging')->sole();
    DaemonJob::query()->where('service', 'shop-db-staging')->sole()->forceFill(['state' => 'succeeded'])->save();
    $stage->forceFill(['inventory' => ['services' => [['key' => 'docker:abc', 'name' => 'serveros-shop-db-staging-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db-staging']]]]])->save();
    networkUp($stage, 'stage-key');

    $stage->forceFill(['daemon_version' => '0.1.1-pre.30'])->save();
    app(ProjectData::class)->start($copy->fresh(), $workspace->owner);
    expect($copy->fresh()->data_state)->toBe('failed')->and($copy->fresh()->data_error)->toContain('too old');

    $stage->forceFill(['daemon_version' => '0.1.1-pre.40'])->save();
    app(ProjectData::class)->start($copy->fresh(), $workspace->owner);
    $job = DaemonJob::query()->where('kind', 'service_exec')->sole();
    $job->forceFill(['state' => 'succeeded', 'result' => ['exit_code' => 1]])->save();
    app(ProjectData::class)->jobFinished($job->fresh());
    expect($copy->fresh()->data_state)->toBe('failed')->and($copy->fresh()->data_error)->toContain('exit code 1');

    // A refresh doesn't start a second copy while one is running.
    $copy->fresh()->forceFill(['data_state' => 'copying'])->save();
    $before = DaemonJob::query()->where('kind', 'service_exec')->count();
    app(ProjectData::class)->refresh($copy->environment, $workspace->owner);
    expect(DaemonJob::query()->where('kind', 'service_exec')->count())->toBe($before);
});

test('each database’s copy gets the original’s login by name, never in its command', function (string $template, array $env, array $expect) {
    $sourceEnv = ProjectData::sourceEnv($template, $env, '10.64.1.3', '3306');
    expect($sourceEnv)->toMatchArray($expect);
    $command = ProjectData::command($template);
    foreach ($env as $value) {
        expect($command)->not->toContain($value);
    }
    expect($command)->toStartWith('set -e');
})->with([
    'mysql' => ['mysql', ['MYSQL_ROOT_PASSWORD' => 'rootpw', 'MYSQL_DATABASE' => 'shopdb'], ['SOURCE_PASSWORD' => 'rootpw', 'SOURCE_DB' => 'shopdb', 'SOURCE_HOST' => '10.64.1.3']],
    'mariadb' => ['mariadb', ['MARIADB_ROOT_PASSWORD' => 'rootpw', 'MARIADB_DATABASE' => 'shopdb'], ['SOURCE_PASSWORD' => 'rootpw', 'SOURCE_DB' => 'shopdb']],
    'mongodb' => ['mongodb', ['MONGO_USER' => 'shopadmin', 'MONGO_PASSWORD' => 'p@ss/word'], ['SOURCE_URI' => 'mongodb://shopadmin:p%40ss%2Fword@10.64.1.3:3306/?authSource=admin']],
]);

test('promoting refuses when nothing would change', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);
    // Staging at the same code as Production.
    projectDeploy($stage, 'shop-staging', ['type' => 'deploy', 'service' => 'shop-staging', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'env' => [], 'domains' => [], 'port' => 3000]);

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/promote", ['from' => $staging->id, 'to' => $project->production()->id])
        ->assertSessionHasErrors('from');
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/promote", ['from' => $staging->id, 'to' => $staging->id])
        ->assertSessionHasErrors('to');
});

test('settings refuse bad names, build-time ones and a name both set and removed, and an unchanged save deploys nothing', function () {
    [$workspace, $project, , , , $base] = projectWithProduction();
    $app = $project->nodes()->where('name', 'shop')->sole();
    $url = $base."/projects/{$project->uid}/nodes/{$app->id}/settings";

    foreach ([['set' => ['1BAD' => 'x']], ['set' => ['VITE_NAME' => 'x']], ['set' => ['A' => '1'], 'remove' => ['A']]] as $payload) {
        $this->actingAs($workspace->owner)->patch($url, $payload)->assertSessionHasErrors('settings');
    }

    $before = DaemonJob::query()->where('kind', 'deploy')->where('service', 'shop')->count();
    $this->actingAs($workspace->owner)->patch($url, ['set' => ['APP_ENV' => 'production']])->assertSessionHasNoErrors();
    expect(DaemonJob::query()->where('kind', 'deploy')->where('service', 'shop')->count())->toBe($before);
});

test('deleting an environment with its services removes only its containers, data and all', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);
    $stage->forceFill(['inventory' => ['services' => [
        ['key' => 'docker:s1', 'name' => 'serveros-shop-db-staging-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db-staging']],
        ['key' => 'docker:s2', 'name' => 'serveros-shop-staging-'.str_repeat('a', 12), 'kind' => 'app', 'status' => 'running', 'details' => []],
        ['key' => 'docker:other', 'name' => 'something-else', 'kind' => 'container', 'status' => 'running', 'details' => []],
    ]]])->save();

    DaemonJob::query()->where('machine_id', $stage->id)->update(['state' => 'succeeded']);

    $this->actingAs($workspace->owner)->delete($base."/projects/{$project->uid}/environments/{$staging->id}", ['remove_services' => true])->assertRedirect();

    $removed = collect(projectCommands())->filter(fn ($c) => $c['job']['type'] === 'service_remove');
    expect($removed->pluck('job.service')->sort()->values()->all())->toBe(['docker:s1', 'docker:s2'])
        ->and($removed->every(fn ($c) => $c['machine'] === $stage->uid && $c['job']['delete_data'] === true))->toBeTrue();
    // Hidden at once, with its name free, until the machine has nothing of it left.
    expect($project->environments()->pluck('name')->all())->toBe(['Production'])
        ->and($staging->fresh()->removing_at)->not->toBeNull();

    // The next scan shows them gone: the environment goes.
    $stage->forceFill(['inventory' => ['services' => [['key' => 'docker:other', 'name' => 'something-else', 'kind' => 'container', 'status' => 'running', 'details' => []]]]])->save();
    $this->artisan('projects:tick')->assertSuccessful();
    expect($staging->fresh())->toBeNull()->and($project->nodes()->count())->toBe(2);
});

test('people who can’t manage machines, or outside the workspace, can’t change environments', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $outsider = Workspace::factory()->create()->owner;

    foreach ([$viewer, $outsider] as $who) {
        $this->actingAs($who)->post($base."/projects/{$project->uid}/environments/{$staging->id}/refresh")->assertForbidden();
        $this->actingAs($who)->post($base."/projects/{$project->uid}/promote", ['from' => $staging->id, 'to' => $project->production()->id])->assertForbidden();
        $this->actingAs($who)->delete($base."/projects/{$project->uid}/environments/{$staging->id}")->assertForbidden();
    }
    expect($staging->fresh())->not->toBeNull();
});

test('a project from before environments gets its Production when opened', function () {
    [$workspace, , , $base] = projectSetup();
    $project = $workspace->projects()->create(['name' => 'Old']);
    expect($project->environments()->count())->toBe(0);

    $this->actingAs($workspace->owner)->get($base."/projects/{$project->uid}")
        ->assertInertia(fn (Assert $page) => $page->where('environments.0.name', 'Production'));
});

/** GitHub tells ServerOS about a pull request on acme/shop. */
function pullRequest(string $action, int $number, string $head, string $headRepo = 'acme/shop'): TestResponse
{
    $body = json_encode([
        'action' => $action,
        'installation' => ['id' => 42],
        'repository' => ['full_name' => 'acme/shop'],
        'pull_request' => ['number' => $number, 'title' => 'Fix the cart', 'head' => ['sha' => $head, 'repo' => ['full_name' => $headRepo]]],
    ]);

    return test()->call('POST', '/api/github/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'pull_request',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
    ], $body);
}

/** @return array{0: Workspace, 1: Project, 2: Machine, 3: Machine, 4: Machine, 5: string} */
function projectWithPreviews(array $settings = []): array
{
    config()->set('services.github_app.webhook_secret', 'hook-secret');
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'acme', 'account_type' => 'Organization']);
    test()->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/previews", [
        'enabled' => true, 'repo' => 'acme/shop', 'machine' => $stage->id, 'copy_data' => false, 'domain' => 'preview.example.com', 'limit' => 2, ...$settings,
    ])->assertSessionHasNoErrors();

    return [$workspace, $project->fresh(), $web, $db, $stage, $base];
}

test('a pull request gets its own environment on the preview machine, at its code, with its own address', function () {
    [, $project, , , $stage] = projectWithPreviews();

    pullRequest('opened', 12, str_repeat('c', 40))->assertStatus(202);

    $preview = $project->environments()->where('pull_request', 12)->sole();
    expect($preview->name)->toBe('PR #12')->and($preview->head)->toBe(str_repeat('c', 40));
    $nodes = $preview->nodes()->get();
    expect($nodes->pluck('machine_id')->unique()->all())->toBe([$stage->id]);

    $app = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop-pr-12')['job'];
    expect($app['commit'])->toBe(str_repeat('c', 40))
        ->and($app['domains'])->toBe(['shop-pr-12.preview.example.com'])
        ->and($app['env'])->not->toHaveKey('DATABASE_URL');
    expect(collect(projectCommands())->contains(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop-db-pr-12'))->toBeTrue();
});

test('new commits deploy the preview again, and closing removes it with its services', function () {
    [, $project, , , $stage] = projectWithPreviews();
    pullRequest('opened', 12, str_repeat('c', 40));
    $preview = $project->environments()->where('pull_request', 12)->sole();
    projectDeploy($stage, 'shop-pr-12', collect(projectCommands())->last(fn ($c) => ($c['job']['service'] ?? null) === 'shop-pr-12')['job']);

    pullRequest('synchronize', 12, str_repeat('d', 40))->assertStatus(202);
    $redeploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop-pr-12')['job'];
    expect($redeploy['commit'])->toBe(str_repeat('d', 40))
        ->and($redeploy['domains'])->toBe(['shop-pr-12.preview.example.com'])
        ->and($preview->fresh()->head)->toBe(str_repeat('d', 40));

    $stage->forceFill(['inventory' => ['services' => [
        ['key' => 'docker:pr1', 'name' => 'serveros-shop-pr-12-'.str_repeat('d', 12), 'kind' => 'app', 'status' => 'running', 'details' => []],
    ]]])->save();
    pullRequest('closed', 12, str_repeat('d', 40))->assertStatus(202);
    expect($preview->fresh()->removing_at)->not->toBeNull()
        ->and($preview->fresh()->pull_request)->toBeNull()
        ->and(collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'service_remove')['job']['service'])->toBe('docker:pr1');
});

test('pull requests from forks get no preview, and there are never more open than the limit', function () {
    [$workspace, $project] = projectWithPreviews(['limit' => 1]);

    pullRequest('opened', 5, str_repeat('e', 40), headRepo: 'stranger/shop')->assertOk();
    expect($project->environments()->whereNotNull('pull_request')->count())->toBe(0);

    pullRequest('opened', 6, str_repeat('e', 40));
    pullRequest('opened', 7, str_repeat('f', 40));
    expect($project->environments()->whereNotNull('pull_request')->pluck('pull_request')->all())->toBe([6])
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'project.preview_skipped')->exists())->toBeTrue();

    // Opened again (reopened) is the same preview, not a second one.
    pullRequest('reopened', 6, str_repeat('e', 40));
    expect($project->environments()->whereNotNull('pull_request')->count())->toBe(1);
});

test('previews need GitHub connected and a machine with Docker, and stop when turned off or when whoever turned them on can’t manage machines', function () {
    [$workspace, $project, , , $stage, $base] = projectWithProduction();
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/previews", ['enabled' => true, 'repo' => 'acme/shop', 'machine' => $stage->id])
        ->assertSessionHasErrors('repo');
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'acme', 'account_type' => 'Organization']);
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/previews", ['enabled' => true, 'repo' => 'not a repo', 'machine' => $stage->id])
        ->assertSessionHasErrors('repo');
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/previews", ['enabled' => true, 'repo' => 'acme/shop', 'machine' => $stage->id, 'domain' => 'not a domain'])
        ->assertSessionHasErrors('domain');

    config()->set('services.github_app.webhook_secret', 'hook-secret');
    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/previews", ['enabled' => false])->assertSessionHasNoErrors();
    pullRequest('opened', 9, str_repeat('a', 40));
    expect($project->environments()->whereNotNull('pull_request')->count())->toBe(0);

    // Turned on by someone later made a viewer.
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin)->patch($base."/projects/{$project->uid}/previews", ['enabled' => true, 'repo' => 'acme/shop', 'machine' => $stage->id])->assertSessionHasNoErrors();
    $workspace->members()->updateExistingPivot($admin->id, ['role' => 'viewer']);
    pullRequest('opened', 9, str_repeat('a', 40));
    expect($project->environments()->whereNotNull('pull_request')->count())->toBe(0);
});

test('containers are matched by their exact deploy, never by a name that merely starts the same', function () {
    $machine = Machine::factory()->create();
    $machine->forceFill(['inventory' => ['services' => [
        ['key' => 'docker:a', 'name' => 'serveros-shop-'.str_repeat('a', 12), 'details' => []],
        ['key' => 'docker:b', 'name' => 'serveros-shop-db-postgres-1', 'details' => ['compose_project' => 'serveros-shop-db']],
        ['key' => 'docker:c', 'name' => 'serveros-shop-staging-'.str_repeat('b', 12), 'details' => []],
        ['key' => 'docker:d', 'name' => 'serveros-shop-staging-2-'.str_repeat('c', 12), 'details' => []],
        ['key' => 'docker:e', 'name' => 'serveros-shop-db-staging-postgres-1', 'details' => ['compose_project' => 'serveros-shop-db-staging']],
    ]]])->save();

    $keys = fn (string $name) => array_column(Placement::containers($machine, $name), 'key');
    expect($keys('shop'))->toBe(['docker:a'])
        ->and($keys('shop-db'))->toBe(['docker:b'])
        ->and($keys('shop-staging'))->toBe(['docker:c'])
        ->and($keys('shop-staging-2'))->toBe(['docker:d'])
        ->and($keys('shop-db-staging'))->toBe(['docker:e']);
});

test('a copy leaves out settings that reach the original’s services, and says so', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    // Set by hand on Production's app: a URL with its database's password, and a Redis host.
    $last = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    projectDeploy($web, 'shop', [...$last, 'env' => [...$last['env'], 'REPORTS_DB' => 'postgres://app:s3cret@10.0.0.9/app', 'REDIS_HOST' => '10.0.0.9', 'STRIPE_KEY' => 'sk_live_x'], 'domains' => ['shop.example.com']]);

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/environments", [
        'name' => 'Staging', 'source' => $project->production()->id, 'machines' => [$web->id => $stage->id, $db->id => $stage->id],
    ])->assertSessionHasNoErrors()->assertSessionHas('project_notes');

    $copy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop-staging')['job'];
    expect(array_keys($copy['env']))->toBe(['APP_ENV', 'STRIPE_KEY']);
    expect(session('project_notes')[0])->toContain('REPORTS_DB')->toContain('REDIS_HOST')->toContain('DATABASE_URL');
});

test('two connections applied one after the other both end up in the app', function () {
    [$workspace, $web, , $base] = projectSetup(sameMachine: true);
    $owner = $workspace->owner;
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => 'shop'])->assertRedirect();
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => 'shop-db'])->assertRedirect();
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/resources", ['kind' => 'cache', 'machine' => $web->id])->assertRedirect();
    $nodes = $project->nodes()->get()->keyBy('kind');

    // The database link applies (its redeploy still queued), then the cache's deploy finishes.
    $this->actingAs($owner)->post($base."/projects/{$project->uid}/links", ['app' => $nodes['app']->id, 'resource' => $nodes['database']->id])->assertRedirect();
    $cache = DaemonJob::query()->where('service', $nodes['cache']->name)->sole();
    $cache->forceFill(['state' => 'succeeded'])->save();
    app(ProjectLinks::class)->deployFinished($cache->fresh());

    $last = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    expect($last['env'])->toHaveKeys(['DATABASE_URL', 'REDIS_HOST', 'APP_ENV']);
});

test('a copy that never reports back, or whose job finished unseen, is settled by the tick', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    makeStaging($workspace, $project, $web, $db, $stage, $base, copyData: true);
    $copy = ProjectNode::query()->where('name', 'shop-db-staging')->sole();
    $job = projectDeploy($stage, 'x', []);
    $job->forceFill(['kind' => 'service_exec', 'state' => 'succeeded', 'result' => ['exit_code' => 0]])->save();

    // Finished, but the gateway never said: copied.
    $copy->forceFill(['data_state' => 'copying', 'data_job_id' => $job->id])->save();
    app(ProjectData::class)->tick(CarbonImmutable::now());
    expect($copy->fresh()->data_state)->toBe('copied');

    // Still "running" long past its time: failed, so it can be copied again.
    $job->forceFill(['state' => 'running', 'created_at' => now()->subHours(2)])->save();
    $copy->fresh()->forceFill(['data_state' => 'copying'])->save();
    app(ProjectData::class)->tick(CarbonImmutable::now());
    expect($copy->fresh()->data_state)->toBe('failed');

    // Its job gone altogether.
    $copy->fresh()->forceFill(['data_state' => 'copying', 'data_job_id' => (string) Str::uuid()])->save();
    app(ProjectData::class)->tick(CarbonImmutable::now());
    expect($copy->fresh()->data_state)->toBe('failed');
});

test('an environment whose machine is offline is removed once it’s back, and given up on after a day', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);
    DaemonJob::query()->where('machine_id', $stage->id)->update(['state' => 'succeeded']);
    $stage->forceFill(['last_seen_at' => now()->subDay(), 'inventory' => ['services' => [
        ['key' => 'docker:s1', 'name' => 'serveros-shop-db-staging-postgres-1', 'details' => ['compose_project' => 'serveros-shop-db-staging']],
    ]]])->save();

    $this->actingAs($workspace->owner)->delete($base."/projects/{$project->uid}/environments/{$staging->id}", ['remove_services' => true]);
    expect(collect(projectCommands())->contains(fn ($c) => $c['job']['type'] === 'service_remove'))->toBeFalse()
        ->and($staging->fresh())->not->toBeNull();

    // Back online: removed then.
    $stage->forceFill(['last_seen_at' => now()])->save();
    $this->artisan('projects:tick');
    expect(collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'service_remove')['job']['service'])->toBe('docker:s1');

    // Never confirmed gone within a day: given up on, and said so.
    $staging->fresh()->forceFill(['removing_at' => now()->subDays(2)])->save();
    $this->artisan('projects:tick');
    expect($staging->fresh())->toBeNull()
        ->and(Activity::query()->where('action', 'project.environment_leftovers')->sole()->meta['names'])->toContain('shop-db-staging on stage-1');
});

test('turning previews off removes the open ones, and a pull request closed later still removes its preview', function () {
    [$workspace, $project, , , $stage, $base] = projectWithPreviews();
    pullRequest('opened', 1, str_repeat('a', 40));
    pullRequest('opened', 2, str_repeat('b', 40));
    expect($project->environments()->whereNotNull('pull_request')->count())->toBe(2);

    $this->actingAs($workspace->owner)->patch($base."/projects/{$project->uid}/previews", ['enabled' => false])->assertSessionHasNoErrors();
    expect($project->environments()->whereNotNull('pull_request')->count())->toBe(0)
        ->and(ProjectEnvironment::query()->whereNotNull('removing_at')->count())->toBe(2);
});

test('previews get none of Production’s secrets, a new APP_KEY, and an address that keeps the pull request’s number', function () {
    [$workspace, $project, $web] = projectWithPreviews();
    $last = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    projectDeploy($web, 'shop', [...$last, 'env' => ['APP_ENV' => 'production', 'APP_KEY' => 'base64:prod', 'STRIPE_SECRET' => 'sk_live', 'MAIL_PASSWORD' => 'pw', 'MAIL_HOST' => 'smtp'], 'domains' => ['shop.example.com']]);

    pullRequest('opened', 77, str_repeat('c', 40));
    $preview = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop-pr-77')['job'];
    expect(array_keys($preview['env']))->toBe(['APP_ENV', 'MAIL_HOST', 'APP_KEY'])
        ->and($preview['env']['APP_KEY'])->toStartWith('base64:')->not->toBe('base64:prod');

    $long = str_repeat('a', 70);
    expect(Previews::address($long, 1234, 'p.example.com'))->toBe(str_repeat('a', 55).'-pr-1234.p.example.com')
        ->and(strlen(explode('.', Previews::address($long, 1234, 'p.example.com'))[0]))->toBe(63);
});

test('a promote is all or nothing: one app that can’t deploy stops them all, and unrelated environments are refused', function () {
    [$workspace, $project, $web, $db, $stage, $base] = projectWithProduction();
    $staging = makeStaging($workspace, $project, $web, $db, $stage, $base);
    projectDeploy($stage, 'shop-staging', ['type' => 'deploy', 'service' => 'shop-staging', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('b', 40), 'env' => [], 'domains' => [], 'port' => 3000]);
    $web->forceFill(['last_seen_at' => now()->subDay()])->save();
    $before = DaemonJob::query()->where('kind', 'deploy')->where('service', 'shop')->count();

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/promote", ['from' => $staging->id, 'to' => $project->production()->id])
        ->assertSessionHasErrors('from');
    expect(DaemonJob::query()->where('kind', 'deploy')->where('service', 'shop')->count())->toBe($before);

    $other = $project->environments()->create(['name' => 'QA', 'position' => 3, 'source_id' => $project->production()->id]);
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/promote", ['from' => $staging->id, 'to' => $other->id])
        ->assertSessionHasErrors('to');
});

test('the add dialog’s list of deploys is only worked out when asked for', function () {
    [$workspace, $project, , , , $base] = projectWithProduction();
    $this->actingAs($workspace->owner)->get($base."/projects/{$project->uid}")
        ->assertInertia(fn (Assert $page) => $page->missing('candidates')->reloadOnly('candidates', fn (Assert $reload) => $reload->has('candidates')));
});

test('a database added from the menu on another machine is offered on the network at once, before its deploy finishes', function () {
    [$workspace, $web, $db, $base] = projectSetup();
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/nodes", ['machine' => $web->id, 'name' => 'shop'])->assertRedirect();

    $this->actingAs($workspace->owner)->post($base."/projects/{$project->uid}/resources", ['kind' => 'database', 'machine' => $db->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $deploy = DaemonJob::query()->where('machine_id', $db->id)->where('kind', 'deploy')->where('state', 'queued')->sole();
    $port = (int) $deploy->command['job']['port'];

    // Nothing sends db-1 its network again when that deploy finishes, so
    // the first one has to offer the port already.
    $network = collect(projectCommands())->last(fn ($c) => $c['machine'] === $db->uid && $c['job']['type'] === 'network')['job'];
    expect($network['expose'])->toBe([['port' => $port, 'target' => "127.0.0.1:{$port}"]]);
});

test('an AI app can set up a project: create it, put deploys on it, connect the app and add a cache', function () {
    [$workspace, $web] = projectSetup(sameMachine: true);
    $workspace->forceFill(['mcp_call_limit_override' => -1])->save();
    $key = mcpKey($workspace);

    $created = mcpCall($workspace, $key, 'create_project', ['name' => 'Shop']);
    expect($created['error'])->toBeFalse($created['text'])
        ->and($created['data']['environments'][0]['name'])->toBe('Production');
    $project = $workspace->projects()->sole();

    // By name or id, and what can be added when it's wrong.
    expect(mcpCall($workspace, $key, 'add_to_project', ['project' => 'shop', 'machine' => 'web-1', 'service' => 'nope'])['text'])->toContain('What can be added: shop-db, shop');
    // Any case, or one of its containers, finds the deploy.
    foreach (['Shop', 'serveros-shop-db-postgres-1'] as $name) {
        $added = mcpCall($workspace, $key, 'add_to_project', ['project' => $project->uid, 'machine' => 'web-1', 'service' => $name]);
        expect($added['error'])->toBeFalse($added['text']);
    }

    $connected = mcpCall($workspace, $key, 'connect_project_app', ['project' => 'Shop', 'app' => 'shop', 'resource' => 'shop-db']);
    expect($connected['error'])->toBeFalse($connected['text'])
        ->and($connected['data']['connection']['state'])->toBe('applied')
        ->and($connected['data']['connection']['settings'])->toContain('DATABASE_URL')
        // Names only: the password stays in the app.
        ->and(json_encode($connected['data']))->not->toContain('s3cret');
    $redeploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    expect($redeploy['env']['DATABASE_URL'])->toBe('postgres://app:s3cret@172.17.0.1:5432/app');

    $cache = mcpCall($workspace, $key, 'add_project_resource', ['project' => 'Shop', 'kind' => 'cache', 'machine' => 'web-1']);
    expect($cache['error'])->toBeFalse($cache['text'])
        ->and($cache['data']['added']['template'])->toBe('valkey');

    $view = mcpCall($workspace, $key, 'get_project', ['project' => 'Shop'])['data'];
    expect(collect($view['parts'])->pluck('name')->sort()->values()->all())->toHaveCount(3)
        ->and($view['connections'])->toHaveCount(2)
        ->and(mcpCall($workspace, $key, 'list_projects')['data']['projects'][0])->toMatchArray(['name' => 'Shop', 'apps' => 1, 'resources' => 2])
        ->and(Activity::query()->where('action', 'project.link')->sole()->meta)->toMatchArray(['via' => 'mcp']);
});

test('an app’s environment variables change from its own page, but not the ones a connection writes', function () {
    [$workspace, $project, $web, , , $base] = projectWithProduction();
    $url = $base."/machines/{$web->id}/apps/shop/variables";

    $this->actingAs($workspace->owner)->getJson($url)->assertOk()
        ->assertJsonPath('deployed', true)
        ->assertJsonPath('names.0', ['name' => 'APP_ENV', 'from' => null, 'build' => false])
        ->assertJsonPath('names.1', ['name' => 'DATABASE_URL', 'from' => 'shop-db', 'build' => false]);

    $this->actingAs($workspace->owner)->putJson($url, ['set' => ['DATABASE_URL' => 'x']])->assertUnprocessable();

    $this->actingAs($workspace->owner)->putJson($url, ['set' => ['MAINTENANCE' => 'true']])->assertOk()
        ->assertJsonPath('changed', ['MAINTENANCE']);
    $deploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'shop')['job'];
    expect($deploy['env']['MAINTENANCE'])->toBe('true')
        ->and($deploy['env']['APP_ENV'])->toBe('production')
        ->and($deploy['domains'])->toBe(['shop.example.com']);

    // A template's settings aren't changed this way.
    $this->actingAs($workspace->owner)->getJson($base."/machines/{$project->nodes()->where('name', 'shop-db')->sole()->machine_id}/apps/shop-db/variables")->assertNotFound();
});

test('an app whose deploys all failed shows as failed, keeps its settings to fix, and deletes without touching the machine', function () {
    [$workspace, $web, , $base] = projectSetup();
    $web->forceFill(['daemon_version' => '0.1.1-pre.40'])->save();
    $failed = projectDeploy($web, 'vote', ['type' => 'deploy', 'service' => 'vote', 'repo' => 'https://github.com/acme/vote', 'commit' => str_repeat('c', 40), 'env' => ['MAINTENANCE' => 'false'], 'port' => 3001]);
    $failed->forceFill(['state' => 'failed'])->save();
    $project = $workspace->projects()->create(['name' => 'Vote']);
    $node = $project->nodes()->create(['environment_id' => $project->production()->id, 'machine_id' => $web->id, 'name' => 'vote', 'kind' => 'app']);

    $this->actingAs($workspace->owner)->get($base."/projects/{$project->uid}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('nodes.0.status', null)
            ->where('nodes.0.failed', true)
            ->where('nodes.0.href', null));

    $url = $base."/machines/{$web->id}/apps/vote/variables";
    $this->actingAs($workspace->owner)->getJson($url)->assertOk()
        ->assertJsonPath('names.0.name', 'MAINTENANCE');
    $this->actingAs($workspace->owner)->putJson($url, ['set' => ['MAINTENANCE' => 'true']])->assertOk();
    $deploy = collect(projectCommands())->last(fn ($c) => $c['job']['type'] === 'deploy' && $c['job']['service'] === 'vote')['job'];
    expect($deploy['env']['MAINTENANCE'])->toBe('true')->and($deploy['commit'])->toBe(str_repeat('c', 40));

    $sent = count(projectCommands());
    $this->actingAs($workspace->owner)->delete($base."/projects/{$project->uid}/nodes/{$node->id}", ['delete' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(ProjectNode::query()->find($node->id))->toBeNull()
        ->and(collect(projectCommands())->slice($sent)->where('job.type', 'service_remove'))->toBeEmpty();
});

test('deleting an app from a project removes every version of it from the machine, without deploying it again', function () {
    [$workspace, $project, $web, , , $base] = projectWithProduction();
    $inventory = $web->inventory;
    $inventory['services'][] = ['key' => 'docker:shop-old', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'kind' => 'app', 'status' => 'stopped', 'origin' => 'created', 'details' => ['managed' => 'true']];
    $web->forceFill(['inventory' => $inventory])->save();
    $app = $project->nodes()->where('name', 'shop')->sole();
    $url = $base."/projects/{$project->uid}/nodes/{$app->id}";

    $this->actingAs($workspace->owner)->delete($url, ['delete' => true, 'confirm' => 'nope'])->assertSessionHasErrors('confirm');
    expect(ProjectNode::query()->find($app->id))->not->toBeNull();

    $sent = count(projectCommands());
    $this->actingAs($workspace->owner)->delete($url, ['delete' => true, 'confirm' => 'shop', 'delete_data' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $after = collect(projectCommands())->slice($sent)->values();
    expect(ProjectNode::query()->find($app->id))->toBeNull()
        ->and(ProjectLink::query()->where('app_node_id', $app->id)->exists())->toBeFalse()
        ->and($after->where('job.type', 'deploy')->where('job.service', 'shop'))->toBeEmpty()
        // The retired version goes first and keeps the data for the live one to take.
        ->and($after->firstWhere('job.service', 'docker:shop-old')['job'])->toMatchArray(['type' => 'service_remove', 'delete_data' => false])
        // The live one isn't adopted yet, so it's adopted on the way.
        ->and($after->firstWhere('job.service', 'docker:shop')['job']['type'])->toBe('adopt');
});
