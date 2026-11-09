<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\ImpersonationView;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Files\FileJobs;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * An online machine with an app deployed from code, an adopted service
 * with a working and a data folder, and a service only discovered.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function filesMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa111', 'name' => 'serveros-blog-0123456789ab', 'kind' => 'web', 'manager' => 'docker',
                'status' => 'running', 'ports' => [], 'confidence' => 90, 'origin' => 'created', 'details' => ['managed' => 'true']],
            ['key' => 'systemd:bot.service', 'name' => 'bot', 'kind' => 'app', 'manager' => 'systemd', 'status' => 'running',
                'ports' => [], 'confidence' => 90, 'origin' => 'discovered', 'working_dir' => '/srv/bot/', 'data_dir' => '/var/lib/bot',
                'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd', 'status' => 'running',
                'ports' => [80], 'confidence' => 90, 'origin' => 'discovered', 'working_dir' => '/etc/nginx', 'details' => []],
        ]],
    ])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'blog', 'state' => 'succeeded',
        'command' => ['job' => ['repo' => 'https://github.com/acme/blog', 'commit' => '0123456789abcdef']], 'finished_at' => now(),
    ]);

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/files'];
}

function lastFileJob(): array
{
    $request = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->last();

    return json_decode($request->body(), true)['command']['job'];
}

/** 2FA on, with the authenticator accepting only "123456", and unlocked. */
function unlockFiles(User $user, Workspace $workspace): void
{
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    test()->actingAs($user)->postJson('https://'.$workspace->host().'/console/unlock', ['code' => '123456'])->assertOk();
}

function finishFileJob(Machine $machine, string $id, string $state, ?array $result = null, ?array $error = null): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => array_filter(['job_id' => $id, 'state' => $state, 'result' => $result, 'error' => $error]),
        ]]])
        ->assertOk();
}

test('the machine and service pages know where each service’s files are', function () {
    [$workspace, $machine] = filesMachine();
    $host = 'https://'.$workspace->host();

    $this->actingAs($workspace->owner)->get("{$host}/machines/{$machine->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('files.places', [
                ['label' => 'blog', 'path' => '/var/lib/serveros/apps/blog'],
                ['label' => 'bot', 'path' => '/srv/bot'],
                ['label' => 'bot · data', 'path' => '/var/lib/bot'],
            ])
            ->where('files.maxWrite', FileJobs::MAX_WRITE_BYTES)
            ->has('consoleLock')
            ->etc());

    $this->actingAs($workspace->owner)->get("{$host}/machines/{$machine->id}/services/app:blog")
        ->assertInertia(fn (Assert $page) => $page->where('files.places', [['label' => 'blog', 'path' => '/var/lib/serveros/apps/blog']])->etc());
    // Only discovered: ServerOS may not look.
    $this->actingAs($workspace->owner)->get("{$host}/machines/{$machine->id}/services/systemd:nginx.service")
        ->assertInertia(fn (Assert $page) => $page->where('files.places', [])->etc());
});

test('listing a folder needs manage machines, but not the unlock', function () {
    [$workspace, $machine, $url] = filesMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson($url, ['op' => 'list', 'path' => '/srv/bot'])->assertForbidden();

    $jobId = $this->actingAs($workspace->owner)->postJson($url, ['op' => 'list', 'path' => '/srv/bot/'])
        ->assertAccepted()->json('jobId');
    expect(lastFileJob())->toBe(['type' => 'list_dir', 'path' => '/srv/bot']);

    $this->actingAs($workspace->owner)->getJson("{$url}/{$jobId}")->assertOk()->assertJson(['state' => 'queued', 'result' => null]);
    finishFileJob($machine, $jobId, 'succeeded', ['entries' => [['name' => 'config.yml', 'kind' => 'file', 'size' => 12, 'mode' => 420, 'modified_ts' => 1]]]);
    $this->actingAs($workspace->owner)->getJson("{$url}/{$jobId}")
        ->assertOk()->assertJson(['state' => 'succeeded', 'result' => ['entries' => [['name' => 'config.yml']]]]);

    // Browsing isn't news: no activity, and not in the machine's jobs.
    expect(Activity::query()->count())->toBe(0);
    $this->actingAs($workspace->owner)->get('https://'.$workspace->host()."/machines/{$machine->id}")
        ->assertInertia(fn (Assert $page) => $page->where('jobs', fn ($jobs) => collect($jobs)->every(fn ($j) => ! str_starts_with($j['kind'], 'file_')))->etc());
});

test('paths must be full, without . or .. in them', function () {
    [$workspace, , $url] = filesMachine();

    foreach (['srv/bot', '/srv/../etc', '/srv/./bot', '/srv/bot/..', "/srv/bot\nx"] as $path) {
        $this->actingAs($workspace->owner)->postJson($url, ['op' => 'list', 'path' => $path])->assertUnprocessable();
    }
    $this->actingAs($workspace->owner)->postJson($url, ['op' => 'list', 'path' => '/srv/bot/.env'])->assertAccepted();
});

test('opening a file needs two-factor and the unlock, and its content is handed over once and never kept', function () {
    [$workspace, $machine, $url] = filesMachine();
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson($url, ['op' => 'read', 'path' => '/srv/bot/.env'])->assertForbidden()->assertJson(['code' => 'two_factor_required']);
    $owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($owner)->postJson($url, ['op' => 'read', 'path' => '/srv/bot/.env'])->assertStatus(423)->assertJson(['code' => 'console_locked']);

    unlockFiles($owner, $workspace);
    $jobId = $this->actingAs($owner)->postJson($url, ['op' => 'read', 'path' => '/srv/bot/.env'])->assertAccepted()->json('jobId');
    expect(lastFileJob())->toBe(['type' => 'read_file', 'path' => '/srv/bot/.env']);

    finishFileJob($machine, $jobId, 'succeeded', ['size' => 13, 'content_b64' => base64_encode('TOKEN=hunter2')]);
    $raw = DB::table('daemon_jobs')->where('id', $jobId)->value('result');
    expect($raw)->not->toContain(base64_encode('TOKEN=hunter2'))->not->toContain('hunter2');

    // Someone else in the workspace can't pick it up.
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin)->getJson("{$url}/{$jobId}")->assertNotFound();

    $this->actingAs($owner)->getJson("{$url}/{$jobId}")->assertOk()
        ->assertJson(['state' => 'succeeded', 'result' => ['size' => 13, 'content_b64' => base64_encode('TOKEN=hunter2')]]);
    $this->actingAs($owner)->getJson("{$url}/{$jobId}")->assertOk()->assertJsonMissingPath('result.content_b64');
    expect(DaemonJob::query()->find($jobId)->result)->toBe(['size' => 13, 'delivered' => true]);
});

test('saving a file goes in the activity, and its content goes once the daemon has it', function () {
    [$workspace, $machine, $url] = filesMachine();
    unlockFiles($workspace->owner, $workspace);

    $jobId = $this->actingAs($workspace->owner)->postJson($url, ['op' => 'write', 'path' => '/srv/bot/config.yml', 'content' => base64_encode("debug: true\n")])
        ->assertAccepted()->json('jobId');
    expect(lastFileJob())->toBe(['type' => 'write_file', 'path' => '/srv/bot/config.yml', 'content_b64' => base64_encode("debug: true\n")]);

    $activity = Activity::query()->latest('id')->first();
    expect($activity->action)->toBe('file.write')
        ->and($activity->subject)->toBe('/srv/bot/config.yml')
        ->and($activity->meta)->toBe(['bytes' => 12])
        ->and(ActivityLine::sentence($activity))->toBe("{$workspace->owner->name} saved /srv/bot/config.yml on {$machine->name}");

    finishFileJob($machine, $jobId, 'succeeded', ['backup' => '/srv/bot/config.yml.serveros-backup-1', 'bytes' => 12]);
    expect(DaemonJob::query()->find($jobId)->command['job'])->not->toHaveKey('content_b64');
    $this->actingAs($workspace->owner)->getJson("{$url}/{$jobId}")->assertJson(['result' => ['backup' => '/srv/bot/config.yml.serveros-backup-1']]);
});

test('uploads over the limit, or that aren’t base64, are turned away', function () {
    [$workspace, , $url] = filesMachine();
    unlockFiles($workspace->owner, $workspace);

    $this->actingAs($workspace->owner)->postJson($url, ['op' => 'write', 'path' => '/srv/bot/big.bin', 'content' => base64_encode(str_repeat('x', FileJobs::MAX_WRITE_BYTES + 1))])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
    $this->actingAs($workspace->owner)->postJson($url, ['op' => 'write', 'path' => '/srv/bot/a.txt', 'content' => 'not base64!'])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
    expect(DaemonJob::query()->where('kind', 'file_write')->count())->toBe(0);
});

test('deleting goes in the activity, and a path outside the daemon’s folders is explained', function () {
    [$workspace, $machine, $url] = filesMachine();
    unlockFiles($workspace->owner, $workspace);

    $jobId = $this->actingAs($workspace->owner)->postJson($url, ['op' => 'delete', 'path' => '/etc/passwd'])->assertAccepted()->json('jobId');
    expect(lastFileJob())->toBe(['type' => 'delete_file', 'path' => '/etc/passwd'])
        ->and(Activity::query()->latest('id')->first()->action)->toBe('file.delete');

    finishFileJob($machine, $jobId, 'failed', error: ['phase' => 'files', 'message' => '/etc/passwd is outside the directories ServerOS may reach']);
    $this->actingAs($workspace->owner)->getJson("{$url}/{$jobId}")
        ->assertJson(['state' => 'failed', 'result' => null, 'error' => ['message' => 'ServerOS can’t reach /etc/passwd. It can only open the folders of services it runs or has adopted.']]);
});

test('an offline machine can’t take file jobs', function () {
    [$workspace, $machine, $url] = filesMachine();
    $machine->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();

    $this->actingAs($workspace->owner)->postJson($url, ['op' => 'list', 'path' => '/srv/bot'])->assertStatus(409);
});

test('containers ServerOS manages offer the folders mounted into them, and templates their compose files, never system folders', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $service = fn (array $s) => ['kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90, ...$s];
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        // A Pterodactyl container someone adopted: its files are in its mounts.
        $service(['key' => 'docker:bbb222', 'name' => '8f2c-wings', 'origin' => 'discovered', 'details' => ['managed' => 'true',
            'mounts' => '/var/lib/serveros/volumes/8f2c:/home/container, /run/wings/machine-id/8f2c:/etc/machine-id, /var/run/docker.sock:/var/run/docker.sock, /etc:/host-etc']]),
        // A template ServerOS deployed: its compose files and its data volume.
        $service(['key' => 'docker:ccc333', 'name' => 'serveros-pg-postgres-1', 'origin' => 'created', 'working_dir' => '/var/lib/serveros/jobs/abc',
            'details' => ['managed' => 'true', 'mounts' => '/var/lib/docker/volumes/pg_data/_data:/var/lib/postgresql/data']]),
        // Only discovered: nothing.
        $service(['key' => 'docker:ddd444', 'name' => 'stranger', 'origin' => 'discovered', 'details' => ['mounts' => '/srv/stranger:/data']]),
    ]]])->save();

    expect(FileJobs::places($machine))->toBe([
        ['label' => '8f2c-wings · /home/container', 'path' => '/var/lib/serveros/volumes/8f2c'],
        ['label' => 'serveros-pg-postgres-1 · /var/lib/postgresql/data', 'path' => '/var/lib/docker/volumes/pg_data/_data'],
        ['label' => 'serveros-pg-postgres-1 · compose files', 'path' => '/var/lib/serveros/jobs/abc'],
    ]);
});

test('unlocking the console is recorded, and files staff open while viewing as someone are noted by path', function () {
    [$workspace, $machine, $url] = filesMachine();
    $owner = $workspace->owner;
    $owner->forceFill(['name' => 'Sam', 'two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $boss = User::factory()->admin()->create();
    $this->actingAs($boss)->post(viewAsUrl($owner), ['reason' => 'Config is wrong']);
    $this->postJson('https://'.$workspace->host().'/console/unlock', ['code' => '123456'])->assertOk();
    $this->postJson($url, ['op' => 'read', 'path' => '/srv/bot/.env'])->assertAccepted();

    expect(ActivityLine::sentence(Activity::query()->sole()))->toBe('ServerOS Support unlocked the console')
        ->and(ImpersonationView::query()->where('route', 'workspace.machines.files')->sole()->parameters)
        ->toBe(['machine' => (string) $machine->id, 'path' => '/srv/bot/.env']);
});
