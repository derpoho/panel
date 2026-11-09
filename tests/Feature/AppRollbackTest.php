<?php

use App\Models\Activity;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Backups\BackupRunner;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppDatabase;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\AppVersions;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const ROLLBACK_OLD = 'aaaaaaaaaaaa1111111111111111111111111111';
const ROLLBACK_NEW = 'bbbbbbbbbbbb2222222222222222222222222222';

/**
 * A machine with an app, shop, deployed twice from Git: the second deploy
 * snapshotted the first version's data.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function rollbackSetup(bool $snapshot = true): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        ['key' => 'docker:old', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'stopped', 'details' => ['managed' => 'true', 'backup' => 'tar']],
        ['key' => 'docker:new', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true', 'backup' => 'tar']],
    ]]])->save();

    rollbackDeploy($machine, ROLLBACK_OLD, [], now()->subDay());
    $second = rollbackDeploy($machine, ROLLBACK_NEW, $snapshot ? [
        ['service' => 'docker:old', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'snapshot' => '1760000000-predeploy', 'bytes' => 2048],
    ] : [], now()->subHour());
    BackupRunner::recordPreDeploy($second);

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

/** @param  list<array<string, mixed>>  $snapshots */
function rollbackDeploy(Machine $machine, string $commit, array $snapshots, $at): DaemonJob
{
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $machine->workspace->owner_id,
        'kind' => 'deploy',
        'service' => 'shop',
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit, 'domains' => ['shop.example.com']]],
        'result' => ['commit' => $commit, 'pre_deploy_snapshots' => $snapshots],
    ]);
    $job->forceFill(['created_at' => $at])->save();

    return $job->load('machine');
}

/** @return list<array<string, mixed>> */
function rollbackCommands(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (GatewayRequest $r) => json_decode($r->body(), true)['command']['job'])
        ->values()
        ->all();
}

test('deploys snapshot data first unless the app has it turned off', function () {
    [$workspace, $machine, $base] = rollbackSetup();
    $queue = app(DaemonJobQueue::class);

    $queue->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'commit' => 'c']);
    expect(rollbackCommands()[0]['snapshot_before'])->toBeTrue();

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['snapshot_before' => false])
        ->assertOk()->assertJson(['snapshotBefore' => false]);
    $queue->deploy($machine->fresh(), $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'commit' => 'd']);
    expect(rollbackCommands()[1]['snapshot_before'])->toBeFalse();

    // Turning it off is recorded; saving it off again isn't.
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['snapshot_before' => false])->assertOk();
    $settings = Activity::query()->where('action', 'app.settings')->sole();
    expect($settings->meta)->toBe(['snapshot_before' => false])
        ->and(ActivityLine::describe($settings, false)['verb'])->toBe('turned off the snapshot before each deploy of');

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/nope/settings", ['snapshot_before' => false])->assertNotFound();
});

test('a deploy’s snapshots show in the backups history once', function () {
    [, $machine] = rollbackSetup();
    BackupRunner::recordPreDeploy(DaemonJob::query()->where('kind', 'deploy')->latest('created_at')->first()->load('machine'));

    $row = Backup::query()->sole();
    expect($row)->trigger->toBe('pre-deploy')->service->toBe('docker:old')->snapshot->toBe('1760000000-predeploy')
        ->service_name->toBe('serveros-shop-aaaaaaaaaaaa')->bytes->toBe(2048)->state->toBe('succeeded');
});

test('the versions list says which is live and which can take their data back', function () {
    [, $machine] = rollbackSetup();

    $versions = AppVersions::for($machine, 'shop');
    expect(array_column($versions, 'commit'))->toBe([ROLLBACK_NEW, ROLLBACK_OLD])
        ->and(array_column($versions, 'live'))->toBe([true, false])
        ->and($versions[1]['restore'])->toBe([['service' => 'docker:old', 'snapshot' => '1760000000-predeploy']])
        ->and($versions[0]['restore'])->toBe([]);
});

test('rolling back needs the name typed, and sends the snapshot when data goes too', function () {
    [$workspace, $machine, $base] = rollbackSetup();
    $url = $base."/machines/{$machine->id}/apps/shop/rollback";

    $this->actingAs($workspace->owner)->postJson($url, ['commit' => ROLLBACK_OLD, 'confirm' => 'nope'])->assertUnprocessable();
    $this->actingAs($workspace->owner)->postJson($url, ['commit' => ROLLBACK_NEW, 'confirm' => 'shop'])
        ->assertStatus(409)->assertJsonPath('error', 'That version is already live.');

    $this->actingAs($workspace->owner)->postJson($url, ['commit' => ROLLBACK_OLD, 'confirm' => 'shop'])->assertAccepted();
    $this->actingAs($workspace->owner)->postJson($url, ['commit' => ROLLBACK_OLD, 'confirm' => 'shop', 'with_data' => true])->assertAccepted();

    [$code, $withData] = rollbackCommands();
    expect($code)->toBe(['type' => 'rollback', 'service' => 'shop', 'release' => ROLLBACK_OLD, 'restore' => []])
        ->and($withData['restore'])->toBe([['service' => 'docker:old', 'snapshot' => '1760000000-predeploy']])
        ->and(Activity::query()->where('action', 'service.rollback')->count())->toBe(2);
});

test('without a snapshot, only the code can go back', function () {
    [$workspace, $machine, $base] = rollbackSetup(snapshot: false);

    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/apps/shop/rollback", ['commit' => ROLLBACK_OLD, 'confirm' => 'shop', 'with_data' => true])
        ->assertStatus(409)->assertJsonPath('error', fn (string $e) => str_contains($e, 'no snapshot of its data'));
});

test('a finished rollback makes the old version live, and its restore can be undone', function () {
    [$workspace, $machine] = rollbackSetup();
    $job = app(AppVersions::class)->rollback($machine, $workspace->owner, 'shop', ROLLBACK_OLD, true);
    $job->forceFill(['state' => 'succeeded', 'result' => ['commit' => ROLLBACK_OLD, 'restored' => [
        ['service' => 'docker:old', 'restored' => '1760000000-predeploy', 'pre_restore_snapshot' => '1760009999-prerestore'],
    ]]])->save();
    BackupRunner::recordRollback($job->load('machine'));
    BackupRunner::recordRollback($job);

    expect(AppReleases::live($machine->fresh())['shop'])->toBe('aaaaaaaaaaaa')
        ->and(Backup::query()->where('trigger', 'pre-restore')->sole())->snapshot->toBe('1760009999-prerestore')->service_name->toBe('serveros-shop-aaaaaaaaaaaa');
});

test('pre-deploy snapshots are kept apart from backups when working out what can be restored', function () {
    [, $machine] = rollbackSetup();
    $backup = $machine->backups()->create(['service' => 'docker:old', 'service_name' => 'serveros-shop-aaaaaaaaaaaa', 'trigger' => 'manual', 'state' => 'succeeded']);
    $backup->forceFill(['snapshot' => 'manual-1'])->save();
    foreach (range(1, 4) as $i) {
        $row = $machine->backups()->create(['service' => 'docker:old', 'service_name' => 'serveros-shop-aaaaaaaaaaaa', 'trigger' => 'pre-deploy', 'state' => 'succeeded']);
        $row->forceFill(['snapshot' => "pd-{$i}"])->save();
    }

    expect(BackupRunner::keptOnMachine($machine->id, 'docker:old'))->toBe([$backup->id])
        ->and(BackupRunner::keptOnMachine($machine->id, 'docker:old', true))->toHaveCount(3)
        ->and(Backup::query()->where('snapshot', '1760000000-predeploy')->value('id'))->not->toBeIn(BackupRunner::keptOnMachine($machine->id, 'docker:old', true));
});

test('AI apps roll back with the name typed back', function () {
    [$workspace, $machine] = rollbackSetup();
    $key = mcpKey($workspace);

    $app = mcpCall($workspace, $key, 'get_app', ['machine' => 'web-1', 'app' => 'shop']);
    expect($app['data']['snapshotBeforeDeploy'])->toBeTrue()
        ->and($app['data']['rollback'][1])->toMatchArray(['commit' => ROLLBACK_OLD, 'live' => false, 'withData' => true]);

    expect(mcpCall($workspace, $key, 'rollback_app', ['machine' => 'web-1', 'app' => 'shop', 'commit' => 'aaaaaaa'])['text'])->toContain('confirm set to “shop”');
    expect(rollbackCommands())->toBe([]);

    $result = mcpCall($workspace, $key, 'rollback_app', ['machine' => 'web-1', 'app' => 'shop', 'commit' => 'aaaaaaa', 'with_data' => true, 'confirm' => 'shop']);
    expect($result['error'])->toBeFalse()
        ->and(rollbackCommands()[0]['restore'])->toHaveCount(1)
        ->and(Activity::query()->where('action', 'service.rollback')->sole()->meta['via'])->toBe('mcp');
});

/** The machine gets a Postgres container ServerOS dumps, published on 5433. */
function withDatabase(Machine $machine): void
{
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:pg', 'name' => 'serveros-shopdb-postgres-1', 'kind' => 'database', 'ports' => [5433], 'details' => ['managed' => 'true', 'backup' => 'pg_dumpall']];
    $inventory['services'][] = ['key' => 'docker:cache', 'name' => 'serveros-cache-redis-1', 'kind' => 'cache', 'ports' => [6379], 'details' => ['managed' => 'true', 'backup' => 'tar']];
    $machine->forceFill(['inventory' => $inventory])->save();
}

test('an app’s database is found from its settings', function () {
    [, $machine] = rollbackSetup();
    withDatabase($machine);
    $detect = fn (array $env) => AppDatabase::detect($machine, $env)['key'] ?? null;

    expect($detect(['DATABASE_URL' => 'postgres://shop:secret@127.0.0.1:5433/shop']))->toBe('docker:pg')
        ->and($detect(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'serveros-shopdb-postgres-1']))->toBe('docker:pg')
        ->and($detect(['PGHOST' => 'host.docker.internal', 'PGPORT' => '5433']))->toBe('docker:pg')
        ->and($detect(['DATABASE_URL' => 'postgres://db.example.com:5432/shop']))->toBeNull()
        ->and($detect(['REDIS_URL' => 'redis://127.0.0.1:6379']))->toBeNull();
});

test('deploys snapshot the app’s database too, unless it’s set to none', function () {
    [$workspace, $machine, $base] = rollbackSetup();
    withDatabase($machine);
    $queue = app(DaemonJobQueue::class);
    $job = ['type' => 'deploy', 'service' => 'shop', 'commit' => 'c', 'env' => ['DATABASE_URL' => 'postgres://u:p@127.0.0.1:5433/shop']];

    $queue->deploy($machine, $workspace->owner, $job);
    expect(rollbackCommands()[0]['snapshot_also'])->toBe(['docker:pg']);

    $settings = $base."/machines/{$machine->id}/apps/shop/settings";
    $this->actingAs($workspace->owner)->putJson($settings, ['database' => 'none'])->assertOk()->assertJson(['database' => 'none']);
    $queue->deploy($machine->fresh(), $workspace->owner, $job);
    expect(rollbackCommands()[1]['snapshot_also'])->toBe([]);

    // Chosen by hand, by a name that outlives the container's id.
    $this->actingAs($workspace->owner)->putJson($settings, ['database' => 'container:serveros-shopdb-postgres-1'])->assertOk();
    $queue->deploy($machine->fresh(), $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'commit' => 'e']);
    expect(rollbackCommands()[2]['snapshot_also'])->toBe(['docker:pg']);

    $this->actingAs($workspace->owner)->putJson($settings, ['database' => 'container:nope'])->assertUnprocessable();
    $this->actingAs($workspace->owner)->putJson($settings, ['snapshot_before' => false])->assertOk();
    $queue->deploy($machine->fresh(), $workspace->owner, $job);
    expect(rollbackCommands()[3])->toMatchArray(['snapshot_before' => false, 'snapshot_also' => []]);
});

test('rolling back with data puts the database back too', function () {
    [$workspace, $machine] = rollbackSetup(snapshot: false);
    withDatabase($machine);
    $third = rollbackDeploy($machine, 'cccccccccccc3333333333333333333333333333', [
        ['service' => 'docker:new', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'snapshot' => 'app-snap', 'bytes' => 10],
        ['service' => 'docker:pg', 'name' => 'serveros-shopdb-postgres-1', 'snapshot' => 'db-snap', 'bytes' => 99],
    ], now());
    $third->forceFill(['command' => ['job' => [...$third->command['job'], 'snapshot_also' => ['docker:pg']]]])->save();
    BackupRunner::recordPreDeploy($third->fresh()->load('machine'));

    app(AppVersions::class)->rollback($machine, $workspace->owner, 'shop', ROLLBACK_NEW, true);

    expect(rollbackCommands()[0]['restore'])->toBe([
        ['service' => 'docker:new', 'snapshot' => 'app-snap'],
        ['service' => 'docker:pg', 'snapshot' => 'db-snap'],
    ]);
});

test('a deploy of an uploaded zip names the file, not our git address', function () {
    [$workspace, $machine] = rollbackSetup();
    $source = new DeploySource;
    $source->forceFill(['token' => Str::random(48), 'workspace_id' => $workspace->id, 'filename' => 'shop.zip',
        'size' => 10, 'received' => 10, 'status' => 'ready', 'expires_at' => now()->addHour()])->save();

    app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'repo' => $source->repoUrl(), 'commit' => 'c']);

    $entry = Activity::query()->where('action', 'service.deploy')->sole();
    expect($entry->meta['upload'])->toBe('shop.zip')
        ->and(ActivityLine::describe($entry, false)['after'])->toBe('from shop.zip');
});
