<?php

use App\Http\Controllers\AppVersionController;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DeploySources\DeploySourcePacker;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** An app ServerOS deployed from code, and a fresh upload of its next version. */
function deployedApp(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id;

    $last = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'tallyplate',
        'state' => 'succeeded', 'log' => [],
        'command' => ['job' => [
            'type' => 'deploy', 'service' => 'tallyplate', 'repo' => 'https://serveros.test/api/deploy-sources/old.git',
            'commit' => str_repeat('a', 40), 'env' => ['DATABASE_URL' => 'postgres://app@172.17.0.1/app'],
            'domains' => ['unemployment66.com'], 'files' => ['.env.production.local' => 'PUBLIC_URL=https://unemployment66.com'],
        ]],
    ]);

    $source = new DeploySource([
        'token' => Str::random(48), 'filename' => 'tallyplate.zip', 'size' => 10, 'expires_at' => now()->addDay(),
    ]);
    $source->workspace()->associate($workspace);
    $source->forceFill(['user_id' => $workspace->owner_id, 'status' => 'ready', 'commit' => str_repeat('b', 40)])->save();

    return [$workspace, $machine, $url, $last, $source];
}

test('a new version redeploys with the last settings and the new code', function () {
    [$workspace, $machine, $url, $last, $source] = deployedApp();

    $jobId = $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/tallyplate/versions', ['source' => $source->token])
        ->assertAccepted()
        ->json('jobId');

    $sent = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $request) => str_ends_with($request->url(), '/commands'))
        ->map(fn (GatewayRequest $request) => json_decode($request->body(), true))
        ->sole();

    expect($sent['id'])->toBe($jobId)
        ->and($sent['command']['job']['repo'])->toBe($source->repoUrl())
        ->and($sent['command']['job']['commit'])->toBe($source->commit)
        ->and($sent['command']['job']['env'])->toBe($last->command['job']['env'])
        ->and($sent['command']['job']['domains'])->toBe(['unemployment66.com'])
        ->and($sent['command']['job']['files'])->toBe(['.env.production.local' => 'PUBLIC_URL=https://unemployment66.com']);
});

test('the service page knows which deploy is behind a container', function () {
    [, $machine, , $last] = deployedApp();

    expect(AppVersionController::lastDeploy($machine, '/serveros-tallyplate-'.str_repeat('a', 12))?->id)->toBe($last->id)
        ->and(AppVersionController::lastDeploy($machine, 'postgres'))->toBeNull();
});

test('expired uploads, other workspaces and viewers are refused', function () {
    [$workspace, $machine, $url, , $source] = deployedApp();

    $source->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/tallyplate/versions', ['source' => $source->token])
        ->assertUnprocessable()->assertJsonValidationErrors('source');

    $source->forceFill(['expires_at' => now()->addDay()])->save();
    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/not-deployed/versions', ['source' => $source->token])
        ->assertNotFound();

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)
        ->postJson($url.'/apps/tallyplate/versions', ['source' => $source->token])
        ->assertForbidden();

    Http::assertNothingSent();
});

/** Points the last deploy at a real unpacked upload, as a zip deploy would. */
function liveUpload(Workspace $workspace, DaemonJob $last): DeploySource
{
    $source = new DeploySource([
        'token' => Str::random(48), 'filename' => 'tallyplate.zip', 'size' => 10, 'expires_at' => now()->addDay(),
    ]);
    $source->workspace()->associate($workspace);
    $source->forceFill(['user_id' => $workspace->owner_id])->save();
    File::ensureDirectoryExists($source->directory());

    $zip = new ZipArchive;
    $zip->open($source->zipPath(), ZipArchive::CREATE);
    $zip->addFromString('app/package.json', json_encode(['dependencies' => ['next' => '15.0.0']]));
    $zip->close();
    app(DeploySourcePacker::class)->pack($source);

    $job = $last->command['job'];
    $job['repo'] = $source->repoUrl();
    $job['commit'] = $source->commit;
    $last->forceFill(['command' => ['job' => $job]])->save();

    return $source->fresh();
}

test('an app moves to a new domain with the same code, and its settings can move too', function () {
    [$workspace, $machine, $url, $last] = deployedApp();
    $source = liveUpload($workspace, $last);
    $before = $source->commit;

    expect(AppVersionController::mentions($last->fresh()))->toBe(['PUBLIC_URL']);

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/tallyplate/domain', ['domain' => 'https://shop.example.com/', 'move_settings' => true])
        ->assertAccepted();

    $sent = collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/commands'))
        ->map(fn (array $pair) => json_decode($pair[0]->body(), true))
        ->sole();
    $job = $sent['command']['job'];

    // A fresh commit with the same files, so the live release isn't replaced in place.
    $tree = fn (string $commit) => trim(shell_exec('git --git-dir='.escapeshellarg($source->repoPath()).' rev-parse '.$commit.'^{tree}'));
    expect($job['commit'])->not->toBe($before)
        ->and($tree($job['commit']))->toBe($tree($before))
        ->and($source->fresh()->commit)->toBe($job['commit'])
        ->and($job['domains'])->toBe(['shop.example.com'])
        ->and($job['files']['.env.production.local'])->toBe('PUBLIC_URL=https://shop.example.com');

    File::deleteDirectory($source->directory());
});

test('a domain change needs the uploaded code to still be there', function () {
    [$workspace, , $url] = deployedApp();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/tallyplate/domain', ['domain' => 'shop.example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors('domain');

    Http::assertNothingSent();
});

test('pruning keeps the upload a live app was built from', function () {
    [$workspace, , , $last, $spare] = deployedApp();
    $live = liveUpload($workspace, $last);
    $live->forceFill(['expires_at' => now()->subHour()])->save();
    $spare->forceFill(['expires_at' => now()->subHour()])->save();

    $this->artisan('deploy-sources:prune')->assertSuccessful();

    expect($live->fresh()?->expires_at->isFuture())->toBeTrue()
        ->and($spare->fresh())->toBeNull();

    File::deleteDirectory($live->directory());
});
