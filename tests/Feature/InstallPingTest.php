<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\InstallPing;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** The install ping's scheduled event, after loading routes/console.php again as this install. */
function installPingEvent(): ?Event
{
    require base_path('routes/console.php');

    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'install:ping'));
}

/**
 * What `install:ping --show` printed: all of it, and the payload in it.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function installPingShown(): array
{
    Artisan::call('install:ping', ['--show' => true]);
    $output = Artisan::output();
    $json = substr($output, (int) strpos($output, '{'), (int) strrpos($output, '}') - (int) strpos($output, '{') + 1);

    return [$output, json_decode($json, true, flags: JSON_THROW_ON_ERROR)];
}

test('serveros.com never schedules the install ping', function () {
    expect(collect(app(Schedule::class)->events())
        ->contains(fn (Event $event) => str_contains((string) $event->command, 'install:ping')))->toBeFalse();

    expect(installPingEvent())->toBeNull();
});

test('a self-hosted install sends it once a day at its own minute, unless telemetry is off', function () {
    config(['serveros.edition' => 'self-hosted']);

    $event = installPingEvent();
    [$hour, $minute] = array_map('intval', explode(':', InstallPing::dailyAt()));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe("{$minute} {$hour} * * *")
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('SERVEROS_TELEMETRY=false keeps it off the schedule', function () {
    config(['serveros.edition' => 'self-hosted', 'serveros.telemetry.enabled' => false]);

    expect(installPingEvent())->toBeNull();
});

test('the minute comes from the install id, so installs spread out', function () {
    $id = InstallPing::installId();
    $at = InstallPing::dailyAt();

    expect($at)->toMatch('/^([01]\d|2[0-3]):[0-5]\d$/')
        ->and(InstallPing::dailyAt())->toBe($at);

    DB::table('install_identity')->update(['install_id' => '00000000-0000-4000-8000-000000000000']);
    expect(InstallPing::installId())->not->toBe($id);

    $minutes = collect(range(1, 40))->map(function () {
        DB::table('install_identity')->update(['install_id' => (string) Str::uuid()]);

        return InstallPing::dailyAt();
    });
    expect($minutes->unique()->count())->toBeGreaterThan(30);
});

test('the install id is made once and kept in the database', function () {
    $id = InstallPing::installId();

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and(InstallPing::installId())->toBe($id)
        ->and(DB::table('install_identity')->count())->toBe(1);

    // Without the row (a database restored from before it), a new one is made once.
    DB::table('install_identity')->delete();
    $new = InstallPing::installId();
    expect($new)->not->toBe($id)->and(InstallPing::installId())->toBe($new);
});

test('it sends only the allowed fields, as plain counts', function () {
    config(['serveros.edition' => 'self-hosted']);
    $workspace = Workspace::factory()->create();
    User::factory()->count(2)->create();
    Machine::factory()->for($workspace)->create(['last_seen_at' => now()->subDay()]);
    Machine::factory()->for($workspace)->create(['last_seen_at' => now()->subDays(8)]);
    Machine::factory()->for($workspace)->create(['last_seen_at' => null]);

    $payload = InstallPing::payload();

    expect(array_keys($payload))->toBe(InstallPing::FIELDS)
        ->and($payload)->toBe([
            'install_id' => InstallPing::installId(),
            'version' => 'dev',
            'edition' => 'self-hosted',
            'licensed' => false,
            'workspaces' => Workspace::query()->count(),
            'users' => User::query()->count(),
            'machines' => 3,
            'active_machines' => 1,
        ]);

    // Nothing about who or where the install is.
    $sent = json_encode($payload);
    foreach ([$workspace->name, $workspace->slug, $workspace->owner->email, config('app.url'), config('app.domain')] as $private) {
        expect($sent)->not->toContain((string) $private);
    }
});

test('--show prints exactly what would be sent, and sends nothing', function () {
    config(['serveros.edition' => 'self-hosted']);
    Http::fake();
    Workspace::factory()->create();

    [$output, $shown] = installPingShown();

    expect($shown)->toBe(InstallPing::payload())
        ->and($output)->toContain('https://serveros.com/api/installs/ping')
        ->toContain('Sent once a day at '.InstallPing::dailyAt());

    Http::assertNothingSent();
});

test('install:ping sends the payload as JSON to serveros.com', function () {
    config(['serveros.edition' => 'self-hosted']);
    Http::fake(['serveros.com/*' => Http::response(null, 204)]);

    $this->artisan('install:ping')->expectsOutput('Sent.')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === InstallPing::DEFAULT_URL
        && $request->data() === InstallPing::payload()
        && $request->header('User-Agent') === ['ServerOS install ping']);
});

test('the address can be changed', function () {
    config(['serveros.edition' => 'self-hosted', 'serveros.telemetry.url' => 'https://telemetry.example.test/ping']);
    Http::fake();

    $this->artisan('install:ping')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://telemetry.example.test/ping');
});

test('serveros.com, or an install with telemetry off, sends nothing even when asked', function () {
    Http::fake();

    $this->artisan('install:ping')->expectsOutputToContain('Only self-hosted installs')->assertSuccessful();

    config(['serveros.edition' => 'self-hosted', 'serveros.telemetry.enabled' => false]);
    $this->artisan('install:ping')->expectsOutputToContain('SERVEROS_TELEMETRY=false')->assertSuccessful();

    Http::assertNothingSent();
});

test('a ping that fails is logged and never fails anything', function (Closure $answer, string $error) {
    config(['serveros.edition' => 'self-hosted']);
    Log::spy();
    Http::fake($answer);

    $this->artisan('install:ping')->expectsOutputToContain($error)->assertSuccessful();
    expect(InstallPing::send())->toContain($error);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_starts_with($message, 'Install ping:') && str_contains($message, $error))->twice();
})->with([
    'unreachable' => [fn () => fn () => throw new ConnectionException('Connection timed out'), 'Connection timed out'],
    'an error' => [fn () => fn () => Http::response('down', 503), 'answered 503'],
]);

test('packages add numbers or yes/no answers, and nothing else', function () {
    $extensions = app(Extensions::class);
    $extensions->addInstallStat('licensed', fn () => true);
    $extensions->addInstallStat('sso_workspaces', fn () => 2);
    $extensions->addInstallStat('negative', fn () => -1);
    $extensions->addInstallStat('words', fn () => 'acme.example.com');
    $extensions->addInstallStat('broken', fn () => throw new RuntimeException('nope'));

    $payload = InstallPing::payload();

    expect($payload['licensed'])->toBeTrue()
        ->and($payload['sso_workspaces'])->toBe(2)
        ->and($payload)->not->toHaveKeys(['negative', 'words', 'broken'])
        ->and(array_keys($payload))->toBe([...InstallPing::FIELDS, 'sso_workspaces']);

    expect(fn () => $extensions->addInstallStat('machines', fn () => 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $extensions->addInstallStat('Host Name', fn () => 1))->toThrow(InvalidArgumentException::class);
});
