<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\Tools\BackupTools;
use App\Mcp\Tools\LogTools;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\DaemonJob;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Logs\LogIngest;
use App\Support\Logs\LogLevel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [BackupTools::class, LogTools::class]));

/** Server commands on, with a gateway that takes everything. */
function backupLogGateway(): void
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
}

/**
 * An online machine with a scan: by default one service ServerOS manages
 * and one it doesn't.
 *
 * @param  list<array<string, mixed>>|null  $services
 */
function backupLogMachine(Workspace $workspace, string $name = 'game-box', ?array $services = null, bool $online = true): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'ip_address' => '203.0.113.'.random_int(2, 250)]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => $online ? now() : now()->subHour(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => $services ?? [
            ['key' => 'docker:mc', 'name' => 'serveros-mc-mc-1', 'kind' => 'game_server', 'manager' => 'docker', 'status' => 'running', 'ports' => [25565], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx.service', 'kind' => 'web_server', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'details' => []],
        ]],
    ])->save();

    return $machine;
}

/** @return array{0: Workspace, 1: Machine, 2: string} */
function backupLogSetup(Plan $plan = Plan::Pro): array
{
    backupLogGateway();
    $workspace = Workspace::factory()->onPlan($plan)->create(['name' => 'Acme']);

    return [$workspace, backupLogMachine($workspace), mcpKey($workspace)];
}

function backupLogS3(Workspace $workspace, string $name = 'R2', string $status = 'ready'): BackupDestination
{
    $destination = new BackupDestination(['name' => $name, 'type' => 's3', 'settings' => [
        'endpoint' => 'acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'worlds',
        'access_key' => 'AKIA-LOOK-AWAY', 'secret_key' => 'shh-very-secret', 'path_style' => true, 'prefix' => 'serveros',
    ]]);
    $destination->workspace_id = $workspace->id;
    $destination->status = $status;
    $destination->save();

    return $destination;
}

function backupLogViewer(Workspace $workspace): string
{
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    return mcpKey($workspace, $viewer);
}

/** @param  array<string, mixed>  $attributes */
function backupLogBackup(Machine $machine, array $attributes = []): Backup
{
    $backup = new Backup([
        'backup_destination_id' => $attributes['backup_destination_id'] ?? null,
        'service' => $attributes['service'] ?? 'docker:mc',
        'service_name' => $attributes['service_name'] ?? 'serveros-mc-mc-1',
        'trigger' => $attributes['trigger'] ?? 'scheduled',
        'state' => $attributes['state'] ?? 'succeeded',
    ]);
    $backup->machine_id = $machine->id;
    $backup->forceFill(array_intersect_key($attributes, array_flip(['bytes', 'location', 'error', 'finished_at', 'created_at'])))->save();

    return $backup;
}

/** A log line as stored, written at a given time (ms) or now. */
function backupLogLine(Machine $machine, string $message, array $overrides = []): LogEntry
{
    $entry = new LogEntry([
        'machine_id' => $machine->id,
        'service_key' => 'docker:aaa',
        'service' => 'umami-umami-1',
        'logged_at' => now()->getTimestampMs(),
        'level' => LogLevel::detect($message),
        'stream' => 'stdout',
        'message' => $message,
        ...$overrides,
    ]);
    $entry->save();

    return $entry;
}

/** @return list<array<string, mixed>> */
function backupLogCommands(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->map(fn ($r) => $r->data())->values()->all();
}

test('tools are listed by what the connection and role allow', function () {
    [$workspace] = backupLogSetup();

    $all = collect(mcpRequest($workspace, mcpKey($workspace), 'tools/list')->json('result.tools'))->keyBy('name');
    expect($all->keys()->all())->toEqualCanonicalizing(['list_backups', 'list_backup_destinations', 'run_backup', 'restore_backup', 'update_backup_settings', 'search_logs', 'get_log_stats'])
        ->and($all['list_backups']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($all['restore_backup']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true])
        ->and($all['restore_backup']['inputSchema']['required'])->toBe(['backup', 'confirm'])
        ->and($all['search_logs']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($all['run_backup']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false])
        ->and($all['update_backup_settings']['annotations'])->toMatchArray(['readOnlyHint' => false, 'idempotentHint' => true])
        ->and($all['run_backup']['inputSchema']['required'])->toBe(['machine']);

    $reading = ['list_backups', 'list_backup_destinations', 'search_logs', 'get_log_stats'];
    expect(collect(mcpRequest($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'tools/list')->json('result.tools'))->pluck('name')->all())->toEqualCanonicalizing($reading)
        ->and(collect(mcpRequest($workspace, backupLogViewer($workspace), 'tools/list')->json('result.tools'))->pluck('name')->all())->toEqualCanonicalizing($reading);
});

test('list_backup_destinations shows where backups go, never the keys', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $r2 = backupLogS3($workspace);
    $vault = backupLogMachine($workspace, 'vault', []);
    $receiver = new BackupDestination(['name' => 'Vault', 'type' => 'machine', 'machine_id' => $vault->id]);
    $receiver->workspace_id = $workspace->id;
    $receiver->forceFill(['private_key' => 'PRIVATE-OPENSSH-KEY', 'public_key' => 'ssh-ed25519 AAAApublic', 'host_key' => 'ssh-ed25519 AAAAhost', 'status' => 'failed', 'status_message' => 'Port 22 is closed.'])->save();
    $machine->forceFill(['backup_destination_id' => $r2->id, 'backup_schedule' => 'daily'])->save();
    backupLogBackup($machine, ['backup_destination_id' => $r2->id, 'state' => 'failed', 'error' => 'disk full']);
    $latest = backupLogBackup($machine, ['backup_destination_id' => $r2->id, 'state' => 'succeeded', 'bytes' => 2048]);
    backupLogS3(Workspace::factory()->create(), 'Theirs');

    $result = mcpCall($workspace, $key, 'list_backup_destinations');

    expect($result['error'])->toBeFalse()
        ->and($result['data']['count'])->toBe(2)
        ->and(collect($result['data']['destinations'])->pluck('name')->all())->toBe(['R2', 'Vault']);
    [$s3, $sftp] = $result['data']['destinations'];
    expect($s3)->toMatchArray([
        'id' => $r2->id, 'type' => 's3', 'where' => 's3://worlds/serveros', 'status' => 'ready', 'machineCount' => 1,
        'machines' => [['id' => $machine->id, 'name' => 'game-box', 'schedule' => 'daily']],
        'receivingMachine' => null,
    ])
        ->and($s3['lastBackup'])->toMatchArray(['id' => $latest->id, 'state' => 'succeeded', 'machine' => 'game-box'])
        ->and($sftp)->toMatchArray([
            'type' => 'machine', 'where' => 'vault', 'status' => 'failed', 'statusMessage' => 'Port 22 is closed.',
            'receivingMachine' => ['id' => $vault->id, 'name' => 'vault'], 'machines' => [], 'lastBackup' => null,
        ]);

    foreach (['AKIA-LOOK-AWAY', 'shh-very-secret', 'acc.r2.cloudflarestorage.com', 'PRIVATE-OPENSSH-KEY', 'AAAApublic', 'AAAAhost', 'Theirs'] as $secret) {
        expect($result['text'])->not->toContain($secret);
    }
});

test('update_backup_settings picks a destination and schedule as the Backups page does', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $r2 = backupLogS3($workspace);
    $b2 = backupLogS3($workspace, 'B2');

    expect(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'schedule' => 'daily'])['text'])->toContain('Choose where its backups go first.')
        ->and(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box'])['text'])->toContain('Say what to change')
        ->and(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'schedule' => 'hourly'])['text'])->toContain('Invalid arguments');

    // A first destination turns on daily backups, as the page does.
    $first = mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'r2']);
    expect($first['error'])->toBeFalse()
        ->and($first['data']['changed'])->toBeTrue()
        ->and($first['data']['machine'])->toMatchArray([
            'id' => $machine->id, 'schedule' => 'daily', 'canBackUpNow' => true, 'unavailable' => null, 'backupableServices' => 1,
            'destination' => ['id' => $r2->id, 'name' => 'R2', 'type' => 's3', 'status' => 'ready'],
        ])
        ->and($machine->fresh()->only(['backup_destination_id', 'backup_schedule']))->toBe(['backup_destination_id' => $r2->id, 'backup_schedule' => 'daily']);
    $activity = Activity::query()->where('action', 'backups.schedule')->sole();
    expect($activity->subject)->toBe('game-box')
        ->and($activity->user_id)->toBe($workspace->owner->id)
        ->and($activity->machine_id)->toBe($machine->id)
        ->and($activity->meta)->toMatchArray(['schedule' => 'daily', 'destination' => 'R2', 'via' => 'mcp']);

    // The same again changes nothing and isn't recorded.
    $again = mcpCall($workspace, $key, 'update_backup_settings', ['machine' => (string) $machine->id, 'destination' => (string) $r2->id, 'schedule' => 'daily']);
    expect($again['data']['changed'])->toBeFalse()
        ->and(Activity::query()->where('action', 'backups.schedule')->count())->toBe(1);

    // The schedule alone keeps the destination; another destination keeps the schedule.
    expect(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'schedule' => 'weekly'])['data']['machine']['destination']['id'])->toBe($r2->id)
        ->and(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'B2'])['data']['machine'])->toMatchArray(['schedule' => 'weekly', 'destination' => ['id' => $b2->id, 'name' => 'B2', 'type' => 's3', 'status' => 'ready']]);

    // Never itself, never another workspace's.
    $self = new BackupDestination(['name' => 'Itself', 'type' => 'machine', 'machine_id' => $machine->id]);
    $self->workspace_id = $workspace->id;
    $self->save();
    $theirs = backupLogS3(Workspace::factory()->create(), 'Elsewhere');
    expect(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'Itself'])['text'])->toContain('A machine can\'t keep its own backups')
        ->and(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => (string) $theirs->id])['text'])->toContain('No backup destination')
        ->and(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'Elsewhere'])['text'])->toContain('There are: B2, Itself, R2.')
        ->and($machine->fresh()->backup_destination_id)->toBe($b2->id);

    // None: no destination and no schedule.
    $none = mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'none']);
    expect($none['data']['machine'])->toMatchArray(['schedule' => 'off', 'destination' => null, 'canBackUpNow' => false, 'unavailable' => 'Choose where its backups go first.'])
        ->and(Activity::query()->where('action', 'backups.schedule')->latest('id')->first()->meta)->toMatchArray(['schedule' => 'off', 'destination' => null]);

    // A destination still being set up is accepted, with a word about it.
    $pending = backupLogS3($workspace, 'Pending', 'setting_up');
    $set = mcpCall($workspace, $key, 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'Pending', 'schedule' => 'off']);
    expect($set['data']['note'])->toContain('Pending isn\'t ready yet')
        ->and($set['data']['machine'])->toMatchArray(['schedule' => 'off', 'canBackUpNow' => false, 'unavailable' => 'Pending isn\'t ready yet.'])
        ->and($machine->fresh()->backup_destination_id)->toBe($pending->id);
});

test('update_backup_settings needs a role that manages machines and a connection that may change things', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    backupLogS3($workspace);
    $theirs = backupLogMachine(Workspace::factory()->create(), 'theirs');

    expect(mcpCall($workspace, backupLogViewer($workspace), 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'R2'])['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'update_backup_settings', ['machine' => 'game-box', 'destination' => 'R2'])['text'])->toContain('read-only')
        ->and(mcpCall($workspace, $key, 'update_backup_settings', ['machine' => (string) $theirs->id, 'destination' => 'R2'])['text'])->toContain('No machine')
        ->and($machine->fresh()->backup_destination_id)->toBeNull()
        ->and($theirs->fresh()->backup_destination_id)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('run_backup backs up the managed services like Back up now', function () {
    [$workspace, $machine, $key] = backupLogSetup();

    expect(mcpCall($workspace, $key, 'run_backup', ['machine' => 'game-box'])['text'])->toContain('Couldn’t back up game-box: Choose where its backups go first.')
        ->and(Backup::query()->count())->toBe(0);

    $r2 = backupLogS3($workspace);
    $machine->forceFill(['backup_destination_id' => $r2->id])->save();

    $result = mcpCall($workspace, $key, 'run_backup', ['machine' => 'game-box']);
    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['machine' => ['id' => $machine->id, 'name' => 'game-box'], 'started' => 1, 'finished' => false])
        ->and($result['data']['backups'])->toHaveCount(1)
        ->and($result['data']['backups'][0])->toMatchArray([
            'service' => 'docker:mc', 'serviceName' => 'serveros-mc-mc-1', 'trigger' => 'manual', 'state' => 'queued',
            'status' => 'Queued', 'destination' => ['id' => $r2->id, 'name' => 'R2'],
        ])
        ->and($result['data']['jobs'][0])->toMatchArray(['kind' => 'backup_to', 'state' => 'queued', 'service' => 'docker:mc', 'machineId' => $machine->id])
        ->and($result['text'])->not->toContain('shh-very-secret');

    $backup = Backup::query()->sole();
    expect($backup->daemon_job_id)->toBe($result['data']['jobs'][0]['id'])
        ->and(DaemonJob::query()->findOrFail($backup->daemon_job_id)->user_id)->toBe($workspace->owner->id);

    $sent = backupLogCommands();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['command']['job'])->toMatchArray(['type' => 'backup_to', 'service' => 'docker:mc', 'reason' => 'manual', 'prefix' => "serveros/{$workspace->slug}/game-box"]);

    $activity = Activity::query()->where('action', 'backup.run')->sole();
    expect($activity->subject)->toBe('game-box')
        ->and($activity->machine_id)->toBe($machine->id)
        ->and($activity->meta)->toMatchArray(['services' => 1, 'via' => 'mcp']);

    // Nothing managed, offline, or someone else's: refused with the reason.
    $bare = backupLogMachine($workspace, 'bare', []);
    $bare->forceFill(['backup_destination_id' => $r2->id])->save();
    $asleep = backupLogMachine($workspace, 'asleep', online: false);
    $asleep->forceFill(['backup_destination_id' => $r2->id])->save();
    $theirs = backupLogMachine(Workspace::factory()->create(), 'theirs');

    expect(mcpCall($workspace, $key, 'run_backup', ['machine' => 'bare'])['text'])->toContain('Nothing on it is managed by ServerOS yet.')
        ->and(mcpCall($workspace, $key, 'run_backup', ['machine' => 'asleep'])['text'])->toContain('asleep is offline')
        ->and(mcpCall($workspace, $key, 'run_backup', ['machine' => (string) $theirs->id])['text'])->toContain('No machine')
        ->and(Backup::query()->count())->toBe(1);

    expect(mcpCall($workspace, backupLogViewer($workspace), 'run_backup', ['machine' => 'game-box'])['text'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'run_backup', ['machine' => 'game-box'])['text'])->toContain('read-only')
        ->and(Backup::query()->count())->toBe(1);
});

test('run_backup waits for the backups when asked', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $machine->forceFill(['backup_destination_id' => backupLogS3($workspace)->id])->save();

    $started = microtime(true);
    $result = mcpCall($workspace, $key, 'run_backup', ['machine' => 'game-box', 'wait' => 1]);

    expect(microtime(true) - $started)->toBeGreaterThan(0.9)
        ->and($result['data']['finished'])->toBeFalse()
        ->and(mcpCall($workspace, $key, 'run_backup', ['machine' => 'game-box', 'wait' => 60])['text'])->toContain('Invalid arguments');
});

test('run_backup reports backups the server couldn’t be sent, as failed', function () {
    // The gateway no longer knows the machine.
    Http::fake(['127.0.0.1:8090/*' => Http::response('', 404)]);
    [$workspace, $machine, $key] = backupLogSetup();
    $machine->forceFill(['backup_destination_id' => backupLogS3($workspace)->id])->save();

    $result = mcpCall($workspace, $key, 'run_backup', ['machine' => 'game-box']);

    expect($result['error'])->toBeFalse()
        ->and($result['data'])->toMatchArray(['started' => 0, 'finished' => true, 'jobs' => []])
        ->and($result['data']['note'])->toContain('No backup could be sent')
        ->and($result['data']['backups'][0])->toMatchArray(['state' => 'failed', 'error' => 'The daemon is offline. Reconnect it before deploying.', 'jobId' => null])
        // Recorded as the dashboard records it, with what started.
        ->and(Activity::query()->where('action', 'backup.run')->sole()->meta)->toMatchArray(['services' => 0, 'via' => 'mcp']);
});

test('run_backup with @all backs up every machine that can be and says why others were skipped', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $r2 = backupLogS3($workspace);
    $machine->forceFill(['backup_destination_id' => $r2->id])->save();
    $db = backupLogMachine($workspace, 'db-1', [
        ['key' => 'docker:pg', 'name' => 'serveros-pg-postgres-1', 'kind' => 'database', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
        ['key' => 'docker:redis', 'name' => 'serveros-redis-redis-1', 'kind' => 'database', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
    ]);
    $db->forceFill(['backup_destination_id' => $r2->id])->save();
    $asleep = backupLogMachine($workspace, 'asleep', online: false);
    $asleep->forceFill(['backup_destination_id' => $r2->id])->save();
    backupLogMachine($workspace, 'unset');
    $theirs = backupLogMachine(Workspace::factory()->create(), 'theirs');
    $theirs->forceFill(['backup_destination_id' => backupLogS3($theirs->workspace)->id])->save();

    $result = mcpCall($workspace, $key, 'run_backup', ['machine' => '@all']);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['started'])->toBe(3)
        ->and($result['data']['machines'])->toBe([
            ['id' => $db->id, 'name' => 'db-1', 'services' => 2],
            ['id' => $machine->id, 'name' => 'game-box', 'services' => 1],
        ])
        ->and($result['data']['skipped'])->toHaveCount(2)
        ->and($result['data']['skipped'][0])->toMatchArray(['name' => 'asleep'])
        ->and($result['data']['skipped'][0]['reason'])->toContain('asleep is offline')
        ->and($result['data']['skipped'][1])->toBe(['id' => Machine::query()->where('name', 'unset')->value('id'), 'name' => 'unset', 'reason' => 'Choose where its backups go first.'])
        ->and($result['data']['backups'])->toHaveCount(3)
        ->and($result['data']['jobs'])->toHaveCount(3)
        ->and(Backup::query()->where('machine_id', $theirs->id)->exists())->toBeFalse()
        ->and(Backup::query()->pluck('trigger')->unique()->all())->toBe(['manual'])
        ->and(Activity::query()->where('action', 'backup.run')->pluck('subject')->sort()->values()->all())->toBe(['db-1', 'game-box']);

    // None can: an error with each reason, and nothing started.
    [$other, , $otherKey] = backupLogSetup();
    $nothing = mcpCall($other, $otherKey, 'run_backup', ['machine' => '@all']);
    expect($nothing['error'])->toBeTrue()
        ->and($nothing['text'])->toContain('No machine could be backed up.')
        ->and($nothing['text'])->toContain('game-box: Choose where its backups go first.');

    $empty = Workspace::factory()->create();
    expect(mcpCall($empty, mcpKey($empty), 'run_backup', ['machine' => '@all'])['text'])->toContain('Connect a machine first.');
});

test('list_backups shows the history newest first with filters and a cursor', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $r2 = backupLogS3($workspace);
    $b2 = backupLogS3($workspace, 'B2');
    $machine->forceFill(['backup_destination_id' => $r2->id, 'backup_schedule' => 'daily', 'last_backup_at' => now()->subHour()])->save();
    $db = backupLogMachine($workspace, 'db-1', []);

    $old = backupLogBackup($machine, ['backup_destination_id' => $r2->id, 'state' => 'succeeded', 'bytes' => 5 * 1024 * 1024, 'location' => 's3://worlds/serveros/acme/game-box/1', 'created_at' => now()->subDays(3), 'finished_at' => now()->subDays(3)->addMinutes(2)]);
    $failed = backupLogBackup($machine, ['backup_destination_id' => $r2->id, 'state' => 'failed', 'error' => 'The bucket is full.', 'trigger' => 'manual', 'created_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
    $dbBackup = backupLogBackup($db, ['backup_destination_id' => $b2->id, 'service' => 'docker:pg', 'service_name' => 'serveros-pg-postgres-1', 'state' => 'running', 'trigger' => 'workflow']);
    backupLogBackup(backupLogMachine(Workspace::factory()->create(), 'theirs'), ['service_name' => 'not-mine']);

    $all = mcpCall($workspace, $key, 'list_backups');
    expect($all['error'])->toBeFalse()
        ->and(collect($all['data']['backups'])->pluck('id')->all())->toBe([$dbBackup->id, $failed->id, $old->id])
        ->and($all['data'])->toMatchArray(['count' => 3, 'hasMore' => false, 'nextCursor' => null, 'url' => 'https://'.$workspace->host().'/backups'])
        ->and($all['text'])->not->toContain('not-mine');
    expect($all['data']['backups'][2])->toMatchArray([
        'machine' => ['id' => $machine->id, 'name' => 'game-box'], 'service' => 'docker:mc', 'state' => 'succeeded', 'status' => 'Done',
        'bytes' => 5 * 1024 * 1024, 'size' => '5 MB', 'destination' => ['id' => $r2->id, 'name' => 'R2'], 'seconds' => 120,
        'location' => 's3://worlds/serveros/acme/game-box/1', 'trigger' => 'scheduled',
    ])
        ->and($all['data']['backups'][1])->toMatchArray(['state' => 'failed', 'error' => 'The bucket is full.', 'trigger' => 'manual']);

    // Each machine's settings, as the Machines tab shows them.
    expect(collect($all['data']['machines'])->pluck('name')->all())->toBe(['db-1', 'game-box'])
        ->and($all['data']['machines'][1])->toMatchArray([
            'schedule' => 'daily', 'status' => 'online', 'backupableServices' => 1, 'canBackUpNow' => true,
            'destination' => ['id' => $r2->id, 'name' => 'R2', 'type' => 's3', 'status' => 'ready'],
        ])
        ->and($all['data']['machines'][0])->toMatchArray(['schedule' => 'off', 'destination' => null, 'canBackUpNow' => false, 'unavailable' => 'Choose where its backups go first.']);

    $ids = fn (array $args) => collect(mcpCall($workspace, $key, 'list_backups', $args)['data']['backups'])->pluck('id')->all();
    expect($ids(['machine' => 'db-1']))->toBe([$dbBackup->id])
        ->and($ids(['state' => 'failed']))->toBe([$failed->id])
        ->and($ids(['trigger' => 'workflow']))->toBe([$dbBackup->id])
        ->and($ids(['service' => 'serveros-pg-postgres-1']))->toBe([$dbBackup->id])
        ->and($ids(['service' => 'docker:mc']))->toBe([$failed->id, $old->id])
        ->and($ids(['destination' => 'b2']))->toBe([$dbBackup->id])
        ->and($ids(['since' => now()->subDays(2)->toIso8601String()]))->toBe([$dbBackup->id, $failed->id])
        ->and($ids(['until' => now()->subDays(2)->toDateString()]))->toBe([$old->id]);

    $machineOnly = mcpCall($workspace, $key, 'list_backups', ['machine' => 'game-box']);
    expect(collect($machineOnly['data']['machines'])->pluck('name')->all())->toBe(['game-box']);

    $page = mcpCall($workspace, $key, 'list_backups', ['limit' => 2]);
    expect($page['data'])->toMatchArray(['count' => 2, 'hasMore' => true, 'nextCursor' => $failed->id]);
    $next = mcpCall($workspace, $key, 'list_backups', ['limit' => 2, 'before' => $page['data']['nextCursor']]);
    expect(collect($next['data']['backups'])->pluck('id')->all())->toBe([$old->id])
        ->and($next['data']['hasMore'])->toBeFalse()
        ->and($next['data'])->not->toHaveKey('machines');

    expect(mcpCall($workspace, $key, 'list_backups', ['state' => 'exploded'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'list_backups', ['limit' => 500])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'list_backups', ['since' => 'not a time'])['text'])->toContain('isn’t a time')
        ->and(mcpCall($workspace, $key, 'list_backups', ['since' => '2026-05-02', 'until' => '2026-05-01'])['text'])->toContain('since is after until')
        ->and(mcpCall($workspace, $key, 'list_backups', ['machine' => 'theirs'])['text'])->toContain('No machine')
        ->and(mcpCall($workspace, backupLogViewer($workspace), 'list_backups')['data']['count'])->toBe(3);
});

test('search_logs returns the newest lines that match, in order, like the Logs page', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $web = backupLogMachine($workspace, 'web-1', [
        ['key' => 'docker:aaa', 'name' => 'umami-umami-1', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => []],
    ]);
    LogIngest::store($web, ['lines' => [
        ['service' => 'docker:aaa', 'name' => 'umami-umami-1', 'line' => 'GET /health 200'],
        ['service' => 'docker:aaa', 'name' => 'umami-umami-1', 'line' => 'ERROR payment failed for order 42', 'stream' => 'stderr'],
        ['service' => 'docker:aaa', 'name' => 'umami-umami-1', 'line' => 'WARN retrying payment'],
    ]]);
    LogIngest::store($machine, ['lines' => [
        ['service' => 'systemd:nginx.service', 'name' => 'nginx.service', 'priority' => 3, 'stream' => 'journal', 'line' => 'payment upstream down'],
        ['service' => 'systemd:nginx.service', 'name' => 'nginx.service', 'priority' => 7, 'stream' => 'journal', 'line' => 'debug noise'],
    ]]);
    backupLogLine(backupLogMachine(Workspace::factory()->create(), 'theirs'), 'ERROR not yours');

    $all = mcpCall($workspace, $key, 'search_logs');
    expect($all['error'])->toBeFalse()
        ->and(collect($all['data']['entries'])->pluck('message')->all())->toBe(['GET /health 200', 'ERROR payment failed for order 42', 'WARN retrying payment', 'payment upstream down', 'debug noise'])
        ->and($all['data'])->toMatchArray(['count' => 5, 'more' => false, 'retentionDays' => 14, 'note' => null, 'url' => 'https://'.$workspace->host().'/logs'])
        ->and($all['text'])->not->toContain('not yours');
    expect($all['data']['entries'][1])->toMatchArray([
        'machine' => ['id' => $web->id, 'name' => 'web-1'], 'service' => 'umami-umami-1', 'serviceLabel' => 'Umami', 'serviceKey' => 'docker:aaa',
        'level' => 'error', 'stream' => 'stderr',
    ])
        ->and($all['data']['entries'][1]['time'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/')
        ->and($all['data']['entries'][3])->toMatchArray(['machine' => ['id' => $machine->id, 'name' => 'game-box'], 'serviceLabel' => 'Nginx', 'level' => 'error', 'stream' => 'journal']);

    $messages = fn (array $args) => collect(mcpCall($workspace, $key, 'search_logs', $args)['data']['entries'])->pluck('message')->all();
    expect($messages(['level' => 'error']))->toBe(['ERROR payment failed for order 42', 'payment upstream down'])
        ->and($messages(['level' => 'warn']))->toBe(['ERROR payment failed for order 42', 'WARN retrying payment', 'payment upstream down'])
        ->and($messages(['level' => 'info']))->toHaveCount(4)
        ->and($messages(['q' => 'PAYMENT']))->toHaveCount(3)
        ->and($messages(['q' => 'payment', 'service' => 'umami-umami-1']))->toBe(['ERROR payment failed for order 42', 'WARN retrying payment'])
        ->and($messages(['service' => 'Umami', 'level' => 'warn']))->toBe(['ERROR payment failed for order 42', 'WARN retrying payment'])
        ->and($messages(['service' => 'systemd:nginx.service']))->toBe(['payment upstream down', 'debug noise'])
        ->and($messages(['machine' => 'game-box']))->toBe(['payment upstream down', 'debug noise'])
        ->and($messages(['machine' => 'game-box', 'service' => 'nginx']))->toBe(['payment upstream down', 'debug noise']);

    $filtered = mcpCall($workspace, $key, 'search_logs', ['machine' => 'web-1', 'service' => 'umami', 'level' => 'error', 'q' => 'payment']);
    expect($filtered['data']['filters'])->toMatchArray([
        'machine' => ['id' => $web->id, 'name' => 'web-1'], 'service' => ['asked' => 'umami', 'matches' => ['umami', 'umami-umami-1']], 'level' => 'error', 'q' => 'payment',
    ])
        ->and($filtered['data']['url'])->toBe('https://'.$workspace->host().'/logs?machine='.$web->id.'&level=error&q=payment');

    $unknown = mcpCall($workspace, $key, 'search_logs', ['service' => 'postgres']);
    expect($unknown['error'])->toBeTrue()
        ->and($unknown['text'])->toContain('No lines from a service “postgres”')
        ->and($unknown['text'])->toContain('Umami (umami-umami-1)');

    expect(mcpCall($workspace, $key, 'search_logs', ['machine' => 'theirs'])['text'])->toContain('No machine')
        ->and(mcpCall($workspace, $key, 'search_logs', ['level' => 'fatal'])['text'])->toContain('Invalid arguments')
        ->and(mcpCall($workspace, $key, 'search_logs', ['limit' => 501])['error'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'search_logs', ['before' => 5, 'after' => 1])['text'])->toContain('one of before, after and around')
        ->and(mcpCall($workspace, backupLogViewer($workspace), 'search_logs')['data']['count'])->toBe(5)
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'search_logs')['data']['count'])->toBe(5);
});

test('search_logs filters by time, given as ISO 8601 or how long ago', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    backupLogLine($machine, 'two days ago', ['logged_at' => now()->subDays(2)->getTimestampMs()]);
    backupLogLine($machine, 'two hours ago', ['logged_at' => now()->subHours(2)->getTimestampMs()]);
    backupLogLine($machine, 'just now');

    $messages = fn (array $args) => collect(mcpCall($workspace, $key, 'search_logs', $args)['data']['entries'])->pluck('message')->all();
    expect($messages(['since' => '15m']))->toBe(['just now'])
        ->and($messages(['since' => '6h']))->toBe(['two hours ago', 'just now'])
        ->and($messages(['since' => '7d', 'until' => '1h']))->toBe(['two days ago', 'two hours ago'])
        ->and($messages(['until' => now()->subDay()->toIso8601String()]))->toBe(['two days ago'])
        ->and($messages(['since' => now()->subDays(3)->toDateString(), 'until' => now()->subDays(2)->toDateString()]))->toBe(['two days ago'])
        ->and(mcpCall($workspace, $key, 'search_logs', ['since' => 'whenever'])['text'])->toContain('isn’t a time')
        ->and(mcpCall($workspace, $key, 'search_logs', ['since' => '1h', 'until' => '2h'])['text'])->toContain('since is after until');
});

test('search_logs reads JSON lines by their message and cuts long ones', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    backupLogLine($machine, '{"level":"error","ts":1730000000,"logger":"http.log.access","msg":"handled request","status":502,"request":{"uri":"/api"}}', ['service' => 'caddy', 'service_key' => 'docker:caddy']);
    backupLogLine($machine, '{"msg": 42, "note": "not a message"}');
    backupLogLine($machine, str_repeat('x', 3000));

    $entries = mcpCall($workspace, $key, 'search_logs')['data']['entries'];
    expect($entries[0])->toMatchArray([
        'message' => 'handled request', 'structured' => true, 'logger' => 'http.log.access', 'level' => 'error',
        'fields' => ['status' => 502, 'request' => ['uri' => '/api']],
    ])
        ->and($entries[1]['message'])->toBe('{"msg": 42, "note": "not a message"}')
        ->and($entries[1])->not->toHaveKey('structured')
        ->and(mb_strlen($entries[2]['message']))->toBe(2001)
        ->and($entries[2]['truncated'])->toBeTrue();

    $long = mcpCall($workspace, $key, 'search_logs', ['maxChars' => 5000])['data']['entries'][2];
    expect(mb_strlen($long['message']))->toBe(3000)
        ->and($long)->not->toHaveKey('truncated');
});

test('search_logs pages back, follows forward and opens a line in context', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    foreach (range(1, 7) as $i) {
        backupLogLine($machine, "line {$i}");
    }
    backupLogLine($machine, 'elsewhere', ['service' => 'nginx.service', 'service_key' => 'systemd:nginx.service']);
    $ids = LogEntry::query()->orderBy('id')->pluck('id');

    $latest = mcpCall($workspace, $key, 'search_logs', ['limit' => 3, 'service' => 'umami-umami-1']);
    expect(collect($latest['data']['entries'])->pluck('message')->all())->toBe(['line 5', 'line 6', 'line 7'])
        ->and($latest['data'])->toMatchArray(['more' => true, 'olderCursor' => $ids[4], 'newerCursor' => $ids[6]]);

    $older = mcpCall($workspace, $key, 'search_logs', ['limit' => 3, 'service' => 'umami-umami-1', 'before' => $latest['data']['olderCursor']]);
    expect(collect($older['data']['entries'])->pluck('message')->all())->toBe(['line 2', 'line 3', 'line 4'])
        ->and($older['data']['more'])->toBeTrue();
    $oldest = mcpCall($workspace, $key, 'search_logs', ['limit' => 3, 'service' => 'umami-umami-1', 'before' => $older['data']['olderCursor']]);
    expect(collect($oldest['data']['entries'])->pluck('message')->all())->toBe(['line 1'])
        ->and($oldest['data']['more'])->toBeFalse();

    // Following: the next lines in order, not a jump to the newest.
    $after = mcpCall($workspace, $key, 'search_logs', ['limit' => 2, 'after' => $ids[1]]);
    expect(collect($after['data']['entries'])->pluck('message')->all())->toBe(['line 3', 'line 4'])
        ->and($after['data'])->toMatchArray(['more' => true, 'newerCursor' => $ids[3]]);
    $caughtUp = mcpCall($workspace, $key, 'search_logs', ['after' => $ids[7]]);
    expect($caughtUp['data'])->toMatchArray(['count' => 0, 'more' => false, 'newerCursor' => $ids[7]])
        ->and($caughtUp['data']['note'])->toContain('Nothing new');

    // Context: the same service either side, whatever the filters say.
    $around = mcpCall($workspace, $key, 'search_logs', ['around' => $ids[3], 'q' => 'nothing-matches']);
    expect($around['data']['anchor'])->toBe($ids[3])
        ->and(collect($around['data']['entries'])->pluck('message')->all())->toBe(['line 1', 'line 2', 'line 3', 'line 4', 'line 5', 'line 6', 'line 7']);

    $theirs = backupLogLine(backupLogMachine(Workspace::factory()->create(), 'theirs'), 'not yours');
    expect(mcpCall($workspace, $key, 'search_logs', ['around' => $theirs->id])['text'])->toContain("No log line #{$theirs->id}");
});

test('search_logs says why nothing came back', function () {
    $empty = Workspace::factory()->create();
    expect(mcpCall($empty, mcpKey($empty), 'search_logs')['data']['note'])->toContain('connect a machine');

    [$workspace, $machine, $key] = backupLogSetup();
    $quiet = mcpCall($workspace, $key, 'search_logs');
    expect($quiet['data'])->toMatchArray(['count' => 0, 'newerCursor' => 0])
        ->and($quiet['data']['note'])->toContain('Waiting for lines');

    backupLogLine($machine, 'hello');
    expect(mcpCall($workspace, $key, 'search_logs', ['q' => 'goodbye'])['data']['note'])->toContain('No lines match');
});

test('get_log_stats counts lines by level, service and time', function () {
    [$workspace, $machine, $key] = backupLogSetup();
    $web = backupLogMachine($workspace, 'web-1', []);
    backupLogLine($web, 'ERROR one');
    backupLogLine($web, 'ERROR two', ['logged_at' => now()->subHours(3)->getTimestampMs()]);
    backupLogLine($web, 'WARN slow');
    backupLogLine($web, 'fine');
    backupLogLine($machine, 'all good', ['service' => 'nginx.service', 'service_key' => 'systemd:nginx.service']);
    backupLogLine($machine, 'all good again', ['service' => 'nginx.service', 'service_key' => 'systemd:nginx.service']);
    backupLogLine($machine, 'debug', ['service' => 'nginx.service', 'service_key' => 'systemd:nginx.service', 'level' => 'debug']);
    backupLogLine($machine, 'ERROR long ago', ['logged_at' => now()->subDays(2)->subHours(12)->getTimestampMs()]);
    backupLogLine(backupLogMachine(Workspace::factory()->create(), 'theirs'), 'ERROR not yours');

    $stats = mcpCall($workspace, $key, 'get_log_stats');
    expect($stats['error'])->toBeFalse()
        ->and($stats['data'])->toMatchArray(['total' => 7, 'byLevel' => ['error' => 2, 'warn' => 1, 'info' => 3, 'debug' => 1], 'servicesTotal' => 2, 'retentionDays' => 14])
        ->and($stats['data']['services'][0])->toMatchArray([
            'machine' => ['id' => $web->id, 'name' => 'web-1'], 'service' => 'umami-umami-1', 'label' => 'Umami',
            'lines' => 4, 'error' => 2, 'warn' => 1, 'info' => 1, 'debug' => 0,
        ])
        ->and($stats['data']['services'][0]['lastErrorAt'])->not->toBeNull()
        ->and($stats['data']['services'][1])->toMatchArray(['service' => 'nginx.service', 'lines' => 3, 'error' => 0, 'lastErrorAt' => null])
        ->and($stats['data']['machines'])->toBe([
            ['id' => $web->id, 'name' => 'web-1', 'lines' => 4, 'error' => 2, 'warn' => 1],
            ['id' => $machine->id, 'name' => 'game-box', 'lines' => 3, 'error' => 0, 'warn' => 0],
        ]);

    $timeline = $stats['data']['timeline'];
    expect($timeline['bucketMinutes'])->toBe(60)
        ->and(count($timeline['buckets']))->toBeGreaterThanOrEqual(24)
        ->and(array_sum(array_column($timeline['buckets'], 'lines')))->toBe(7)
        ->and(array_sum(array_column($timeline['buckets'], 'error')))->toBe(2)
        ->and(array_sum(array_column($timeline['buckets'], 'warn')))->toBe(1);

    expect(mcpCall($workspace, $key, 'get_log_stats', ['since' => '7d'])['data']['total'])->toBe(8)
        ->and(mcpCall($workspace, $key, 'get_log_stats', ['since' => '1h'])['data']['timeline']['bucketMinutes'])->toBe(5)
        ->and(mcpCall($workspace, $key, 'get_log_stats', ['machine' => 'web-1', 'level' => 'warn'])['data'])->toMatchArray(['total' => 3, 'byLevel' => ['error' => 2, 'warn' => 1, 'info' => 0, 'debug' => 0]])
        ->and(mcpCall($workspace, $key, 'get_log_stats', ['service' => 'nginx'])['data']['total'])->toBe(3)
        ->and(mcpCall($workspace, $key, 'get_log_stats', ['until' => now()->subDays(2)->toIso8601String()])['data']['total'])->toBe(1)
        ->and(mcpCall($workspace, $key, 'get_log_stats', ['top' => 1])['data']['services'])->toHaveCount(1)
        ->and(mcpCall($workspace, $key, 'get_log_stats', ['machine' => 'theirs'])['text'])->toContain('No machine')
        ->and(mcpCall($workspace, backupLogViewer($workspace), 'get_log_stats')['data']['total'])->toBe(7);
});
