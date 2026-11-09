<?php

use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Backups\BackupRunner;
use App\Support\Backups\S3Probe;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A workspace with an online machine running one managed and one
 * unmanaged service, and a fake gateway and storage.
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function backupWorkspace(?object $storage = null): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        '*' => $storage ?? Http::response('', 200),
    ]);

    $workspace = Workspace::factory()->create();
    $machine = onlineMachine($workspace, 'game-box', [
        ['key' => 'docker:mc', 'name' => 'serveros-mc-mc-1', 'kind' => 'game_server', 'ports' => [25565], 'details' => ['managed' => 'true']],
        ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'ports' => [80], 'details' => []],
    ]);

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

/**
 * @param  list<array<string, mixed>>  $services
 */
function onlineMachine(Workspace $workspace, string $name, array $services = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'ip_address' => '203.0.113.'.random_int(2, 250)]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['services' => $services],
    ])->save();

    return $machine;
}

function s3Destination(Workspace $workspace): BackupDestination
{
    $destination = new BackupDestination(['name' => 'R2', 'type' => 's3', 'settings' => [
        'endpoint' => 'acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'worlds',
        'access_key' => 'AKIA1', 'secret_key' => 'shh-secret', 'path_style' => true, 'prefix' => 'serveros',
    ]]);
    $destination->workspace_id = $workspace->id;
    $destination->save();

    return $destination;
}

/** @return list<array<string, mixed>> */
function commandsSent(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (GatewayRequest $r) => json_decode($r->body(), true))
        ->values()
        ->all();
}

function ingestJob(Machine $machine, DaemonJob $job, array $payload): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, ...$payload]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('an S3 bucket is checked with a test upload, and its keys are kept encrypted', function () {
    [$workspace, , $base] = backupWorkspace();

    $this->actingAs($workspace->owner)->post($base.'/backup-destinations', [
        'type' => 's3', 'name' => 'Worlds', 'endpoint' => 'https://s3.eu-west-2.amazonaws.com/',
        'region' => 'eu-west-2', 'bucket' => 'my-worlds', 'access_key' => 'AKIA1', 'secret_key' => 'shh-secret',
        'path_style' => false, 'prefix' => '/serveros/',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $destination = BackupDestination::query()->sole();
    expect($destination->settings['endpoint'])->toBe('s3.eu-west-2.amazonaws.com')
        ->and($destination->settings['prefix'])->toBe('serveros')
        ->and($destination->status)->toBe('ready')
        ->and($destination->getRawOriginal('settings'))->not->toContain('shh-secret');

    Http::assertSent(fn (GatewayRequest $r) => $r->method() === 'PUT'
        && $r->url() === 'https://my-worlds.s3.eu-west-2.amazonaws.com/serveros/.serveros-check'
        && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIA1/'));
});

test('a bucket that turns the keys down is not added, and says why', function () {
    [$workspace, , $base] = backupWorkspace(Http::response('<Error><Code>SignatureDoesNotMatch</Code></Error>', 403));

    $this->actingAs($workspace->owner)->post($base.'/backup-destinations', [
        'type' => 's3', 'name' => 'Worlds', 'endpoint' => 's3.eu-west-2.amazonaws.com', 'region' => 'eu-west-2',
        'bucket' => 'my-worlds', 'access_key' => 'AKIA1', 'secret_key' => 'wrong',
    ])->assertSessionHasErrors(['probe' => 'Those keys were turned down. Check the access key and secret.']);

    expect(BackupDestination::query()->count())->toBe(0);
});

test('setting a receiver up again makes a new key pair, and is recorded', function () {
    [$workspace, , $base] = backupWorkspace();
    $receiver = onlineMachine($workspace, 'vault');
    $this->actingAs($workspace->owner)->post($base.'/backup-destinations', ['type' => 'machine', 'name' => 'Vault', 'machine_id' => $receiver->id])->assertSessionHasNoErrors();
    $destination = BackupDestination::query()->sole();
    $before = $destination->public_key;

    $this->actingAs($workspace->owner)->post($base."/backup-destinations/{$destination->id}/retry")->assertSessionHasNoErrors();

    expect($destination->fresh()->public_key)->not->toBe($before)
        ->and(DaemonJob::query()->where('kind', 'backup_receiver')->count())->toBe(2)
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['backup_destination.add', 'backup_destination.retry']);
});

test('another machine is set up as a receiver and becomes ready with its host key', function () {
    [$workspace, , $base] = backupWorkspace();
    $receiver = onlineMachine($workspace, 'vault');

    $this->actingAs($workspace->owner)->post($base.'/backup-destinations', [
        'type' => 'machine', 'name' => 'Vault', 'machine_id' => $receiver->id,
    ])->assertSessionHasNoErrors();

    $destination = BackupDestination::query()->sole();
    expect($destination->status)->toBe('setting_up')
        ->and($destination->public_key)->toStartWith('ssh-ed25519 ')
        ->and($destination->private_key)->toContain('OPENSSH PRIVATE KEY');

    $job = DaemonJob::query()->where('kind', 'backup_receiver')->sole();
    expect($job->machine_id)->toBe($receiver->id)
        ->and($job->command['job']['public_key'])->toBe($destination->public_key);

    ingestJob($receiver, $job, ['state' => 'succeeded', 'result' => ['user' => 'serveros-backups', 'host_key' => 'ssh-ed25519 AAAAhost']]);

    expect($destination->fresh()->status)->toBe('ready')
        ->and($destination->fresh()->host_key)->toBe('ssh-ed25519 AAAAhost')
        ->and($destination->fresh()->forDaemon())->toMatchArray([
            'type' => 'sftp', 'host' => $receiver->ip_address, 'user' => 'serveros-backups', 'host_key' => 'ssh-ed25519 AAAAhost',
        ]);
});

test('a machine picks a destination and schedule, but never itself', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $destination = s3Destination($workspace);

    $this->actingAs($workspace->owner)->put($base."/machines/{$machine->id}/backups", ['destination_id' => null, 'schedule' => 'daily'])
        ->assertSessionHasErrors('schedule');

    $this->actingAs($workspace->owner)->put($base."/machines/{$machine->id}/backups", ['destination_id' => $destination->id, 'schedule' => 'daily'])
        ->assertSessionHasNoErrors();
    expect($machine->fresh()->backup_destination_id)->toBe($destination->id)
        ->and($machine->fresh()->backup_schedule)->toBe('daily');

    $self = new BackupDestination(['name' => 'Itself', 'type' => 'machine', 'machine_id' => $machine->id]);
    $self->workspace_id = $workspace->id;
    $self->save();
    $this->actingAs($workspace->owner)->put($base."/machines/{$machine->id}/backups", ['destination_id' => $self->id, 'schedule' => 'daily'])
        ->assertSessionHasErrors('destination_id');

    // Another workspace's destination isn't there to pick.
    $other = s3Destination(Workspace::factory()->create());
    $this->actingAs($workspace->owner)->put($base."/machines/{$machine->id}/backups", ['destination_id' => $other->id, 'schedule' => 'daily'])
        ->assertSessionHasErrors('destination_id');
});

test('backing up sends each managed service with the destination, and results land in history', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $machine->forceFill(['backup_destination_id' => s3Destination($workspace)->id])->save();

    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/backups/run")
        ->assertAccepted()
        ->assertJson(['started' => 1]);

    $sent = commandsSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['command']['job'])->toMatchArray([
            'type' => 'backup_to', 'service' => 'docker:mc', 'reason' => 'manual',
            'prefix' => "serveros/{$workspace->slug}/game-box",
        ])
        ->and($sent[0]['command']['job']['destination'])->toMatchArray(['type' => 's3', 'bucket' => 'worlds', 'secret_key' => 'shh-secret']);

    $backup = Backup::query()->sole();
    $job = DaemonJob::query()->findOrFail($backup->daemon_job_id);
    ingestJob($machine, $job, ['state' => 'succeeded', 'result' => ['bytes' => 123456, 'location' => 's3://worlds/serveros/x/1']]);

    expect($backup->fresh())->state->toBe('succeeded')->bytes->toBe(123456)->location->toBe('s3://worlds/serveros/x/1')
        ->and($machine->fresh()->last_backup_at)->not->toBeNull();

    $this->actingAs($workspace->owner)->get($base.'/backups')
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Backups')
            ->where('machines.0.services', 1)
            ->where('destinations.0.where', 's3://worlds/serveros')
            ->missing('destinations.0.settings.secretKey')
            ->where('history.0.state', 'succeeded')
            ->etc());
});

test('a container with nothing outside it is skipped, not failed', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $machine->forceFill(['backup_destination_id' => s3Destination($workspace)->id, 'last_backup_at' => null])->save();
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/backups/run")->assertAccepted();

    $backup = Backup::query()->sole();
    ingestJob($machine, DaemonJob::query()->findOrFail($backup->daemon_job_id), [
        'state' => 'failed', 'error' => ['phase' => 'backup', 'message' => 'serveros-mc-mc-1 keeps no data outside its container'],
    ]);

    expect($backup->fresh()->state)->toBe('skipped')
        ->and($machine->fresh()->last_backup_at)->toBeNull();
});

test('services the daemon says have nothing to copy aren’t backed up', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = onlineMachine($workspace, 'box', [
        ['key' => 'systemd:docker.service', 'name' => 'docker', 'kind' => 'other', 'details' => ['managed' => 'true', 'backup' => 'none']],
        ['key' => 'systemd:postgresql.service', 'name' => 'postgresql', 'kind' => 'database', 'details' => ['managed' => 'true', 'backup' => 'pg_dumpall']],
        ['key' => 'docker:old', 'name' => 'old', 'kind' => 'app', 'details' => ['managed' => 'true']],
    ]);

    expect(array_column($machine->backupableServices(), 'key'))->toBe(['systemd:postgresql.service', 'docker:old']);

    $only = onlineMachine($workspace, 'proxy-box', [
        ['key' => 'systemd:caddy.service', 'name' => 'caddy', 'kind' => 'proxy', 'details' => ['managed' => 'true', 'backup' => 'none']],
    ]);
    $only->forceFill(['backup_destination_id' => s3Destination($workspace)->id])->save();
    expect(app(BackupRunner::class)->unavailable($only))->toBe('Nothing ServerOS manages on it has data to back up. Deploy or adopt an app or database first.');
});

test('a service with no data the daemon can copy is skipped, not failed', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $machine->forceFill(['backup_destination_id' => s3Destination($workspace)->id])->save();
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/backups/run")->assertAccepted();

    $backup = Backup::query()->sole();
    ingestJob($machine, DaemonJob::query()->findOrFail($backup->daemon_job_id), [
        'state' => 'failed', 'error' => ['phase' => 'backup', 'message' => 'ServerOS does not know how to snapshot docker'],
    ]);

    expect($backup->fresh())->state->toBe('skipped')->error->toBeNull()
        ->and(Activity::query()->where('action', 'backup.failed')->exists())->toBeFalse();
});

test('backing up needs a destination and something managed', function () {
    [$workspace, $machine, $base] = backupWorkspace();

    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/backups/run")
        ->assertConflict()->assertJson(['error' => 'Choose where its backups go first.']);

    $bare = onlineMachine($workspace, 'empty');
    $bare->forceFill(['backup_destination_id' => s3Destination($workspace)->id])->save();
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$bare->id}/backups/run")
        ->assertConflict()->assertJson(['error' => 'Nothing on it is managed by ServerOS yet. Deploy or adopt a service first.']);

    $this->actingAs(User::factory()->create())->postJson($base."/machines/{$machine->id}/backups/run")->assertForbidden();
});

test('scheduled backups run when due and not again until the next one', function () {
    [$workspace, $machine] = backupWorkspace();
    $machine->forceFill(['backup_destination_id' => s3Destination($workspace)->id, 'backup_schedule' => 'daily'])->save();
    $off = onlineMachine($workspace, 'off', [['key' => 'docker:x', 'name' => 'x', 'kind' => 'app', 'ports' => [], 'details' => ['managed' => 'true']]]);

    $this->artisan('backups:run-due')->assertSuccessful();
    $this->artisan('backups:run-due')->assertSuccessful();

    expect(Backup::query()->where('machine_id', $machine->id)->where('trigger', 'scheduled')->count())->toBe(1)
        ->and(Backup::query()->where('machine_id', $off->id)->count())->toBe(0);

    $this->travel(24)->hours();
    $machine->forceFill(['last_seen_at' => now()])->save();
    $this->artisan('backups:run-due')->assertSuccessful();
    expect(Backup::query()->where('machine_id', $machine->id)->count())->toBe(2);
});

test('removing a destination turns off the schedules that used it', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $destination = s3Destination($workspace);
    $machine->forceFill(['backup_destination_id' => $destination->id, 'backup_schedule' => 'daily'])->save();

    $this->actingAs($workspace->owner)->delete($base."/backup-destinations/{$destination->id}")->assertRedirect();

    expect($machine->fresh())->backup_destination_id->toBeNull()->backup_schedule->toBe('off');
});

test('S3 requests are signed the AWS way', function () {
    // The example from AWS's SigV4 documentation for a PUT, with the
    // payload hash of an empty body.
    $headers = (new S3Probe)->sign(
        ['region' => 'us-east-1', 'access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'],
        'examplebucket.s3.amazonaws.com',
        '/test%24file.text',
        '',
        '20130524T000000Z',
    );

    expect($headers['x-amz-content-sha256'])->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
        ->and($headers['Authorization'])->toStartWith('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=');
});

/** A finished backup of the managed container, as if the daemon had taken it. */
function finishedBackup(Machine $machine, string $snapshot, array $extra = []): Backup
{
    $backup = $machine->backups()->create(['service' => 'docker:mc', 'service_name' => 'serveros-mc-mc-1', 'trigger' => 'manual', 'state' => 'succeeded']);
    $backup->forceFill(['snapshot' => $snapshot, 'finished_at' => now(), ...$extra])->save();

    return $backup;
}

test('a backup keeps the snapshot the daemon took, so it can be restored', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $machine->forceFill(['backup_destination_id' => s3Destination($workspace)->id])->save();
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/backups/run")->assertAccepted();

    $backup = Backup::query()->sole();
    ingestJob($machine, DaemonJob::query()->findOrFail($backup->daemon_job_id), ['state' => 'succeeded', 'result' => ['snapshot' => '1760000000-manual', 'bytes' => 10, 'location' => 's3://worlds/x']]);

    expect($backup->fresh()->snapshot)->toBe('1760000000-manual');
    $this->actingAs($workspace->owner)->get($base.'/backups')
        ->assertInertia(fn (Assert $page) => $page->where('history.0.cannotRestore', null)->where('history.0.restore', null)->etc());
});

test('restoring needs the service name typed, then sends the snapshot to the machine', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $backup = finishedBackup($machine, '1760000000-manual');

    $this->actingAs($workspace->owner)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'nope'])
        ->assertUnprocessable()->assertJsonPath('errors.confirm.0', 'Type the service’s name to confirm.');
    expect(commandsSent())->toBe([]);

    $this->actingAs($workspace->owner)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])
        ->assertAccepted();

    expect(commandsSent()[0]['command']['job'])->toBe(['type' => 'restore', 'service' => 'docker:mc', 'snapshot' => '1760000000-manual'])
        ->and($backup->fresh()->restore_state)->toBe('queued')
        ->and(Activity::query()->where('action', 'backup.restore')->sole()->subject)->toBe('serveros-mc-mc-1');

    // One restore of a service at a time.
    $this->actingAs($workspace->owner)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])
        ->assertStatus(409)->assertJsonPath('error', 'serveros-mc-mc-1 is being restored now. Wait for it to finish.');
});

test('a container recreated since its backup is restored under its new key', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $backup = finishedBackup($machine, 'snap');
    $backup->forceFill(['service' => 'docker:gone'])->save();

    $this->actingAs($workspace->owner)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertAccepted();

    expect(commandsSent()[0]['command']['job'])->toMatchArray(['service' => 'docker:mc', 'snapshot' => 'snap']);
});

test('a finished restore adds the snapshot taken before it, which undoes it', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $backup = finishedBackup($machine, '1760000000-manual');
    $this->actingAs($workspace->owner)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertAccepted();
    $job = DaemonJob::query()->findOrFail($backup->fresh()->restore_job_id);

    ingestJob($machine, $job, ['state' => 'running']);
    ingestJob($machine, $job, ['state' => 'succeeded', 'result' => ['restored' => '1760000000-manual', 'pre_restore_snapshot' => '1760009999-prerestore']]);
    ingestJob($machine, $job, ['state' => 'succeeded', 'result' => ['restored' => '1760000000-manual', 'pre_restore_snapshot' => '1760009999-prerestore']]);

    $undo = Backup::query()->where('trigger', 'pre-restore')->sole();
    expect($backup->fresh())->restore_state->toBe('succeeded')->restored_at->not->toBeNull()
        ->and($undo)->snapshot->toBe('1760009999-prerestore')->state->toBe('succeeded')->service->toBe('docker:mc');

    $this->actingAs($workspace->owner)->postJson($base."/backups/{$undo->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertAccepted();
    expect(commandsSent()[1]['command']['job']['snapshot'])->toBe('1760009999-prerestore');
});

test('only the backups still kept on the machine can be restored', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $old = finishedBackup($machine, 'old');
    foreach (range(1, 7) as $i) {
        finishedBackup($machine, "new-{$i}");
    }

    $this->actingAs($workspace->owner)->postJson($base."/backups/{$old->id}/restore", ['confirm' => 'serveros-mc-mc-1'])
        ->assertStatus(409)->assertJsonPath('error', fn (string $e) => str_contains($e, 'no longer kept on game-box'));

    $failed = $machine->backups()->create(['service' => 'docker:mc', 'service_name' => 'serveros-mc-mc-1', 'trigger' => 'manual', 'state' => 'failed']);
    $this->actingAs($workspace->owner)->postJson($base."/backups/{$failed->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertStatus(409);
});

test('a restore the machine can’t find says why, in the activity too', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $backup = finishedBackup($machine, 'gone');
    $this->actingAs($workspace->owner)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertAccepted();

    ingestJob($machine, DaemonJob::query()->findOrFail($backup->fresh()->restore_job_id), [
        'state' => 'failed', 'error' => ['phase' => 'restore', 'message' => 'snapshot gone not found'],
    ]);

    expect($backup->fresh())->restore_state->toBe('failed')->restore_error->toContain('no longer kept on game-box')
        ->and(Activity::query()->where('action', 'backup.restore_failed')->exists())->toBeTrue();
});

test('only people who manage machines can restore, and only in their workspace', function () {
    [$workspace, $machine, $base] = backupWorkspace();
    $backup = finishedBackup($machine, 'snap');
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $other = Workspace::factory()->create();

    $this->actingAs($viewer)->postJson($base."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertForbidden();
    $this->actingAs($other->owner)->postJson('https://'.$other->host()."/backups/{$backup->id}/restore", ['confirm' => 'serveros-mc-mc-1'])->assertNotFound();
    expect(commandsSent())->toBe([]);
});

test('AI apps restore with the name typed back, and see what can be restored', function () {
    [$workspace, $machine] = backupWorkspace();
    $backup = finishedBackup($machine, 'snap');
    $key = mcpKey($workspace);

    $listed = mcpCall($workspace, $key, 'list_backups');
    expect($listed['data']['backups'][0])->toMatchArray(['canRestore' => true, 'cannotRestore' => null]);

    expect(mcpCall($workspace, $key, 'restore_backup', ['backup' => $backup->id])['text'])->toContain('pass confirm set to “serveros-mc-mc-1”');
    expect(commandsSent())->toBe([]);

    $result = mcpCall($workspace, $key, 'restore_backup', ['backup' => $backup->id, 'confirm' => 'serveros-mc-mc-1']);
    expect($result['error'])->toBeFalse()
        ->and(commandsSent()[0]['command']['job']['type'])->toBe('restore');
});
