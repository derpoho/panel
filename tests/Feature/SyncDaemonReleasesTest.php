<?php

use App\Models\DaemonRelease;
use App\Support\DaemonUpdates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const RELEASE_FEED = 'https://serveros.com/api/daemon/releases/latest';

/** What serveros.com's feed answers for one release. */
function feedRelease(string $version, string $channel = 'stable'): array
{
    return [
        'version' => $version,
        'url' => "https://releases.serveros.com/{$version}/serverosd-{$version}-linux-amd64",
        'sha256' => hash('sha256', $version),
        'signature' => base64_encode(str_repeat('s', 64)),
        'channel' => $channel,
        'min_from' => null,
        'notes_url' => null,
    ];
}

test('a self-hosted panel copies serveros.com’s daemon releases, so its machines update', function () {
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match ([$query['channel'], $query['arch']]) {
            ['stable', 'amd64'] => Http::response(feedRelease('v0.1.1-pre.36')),
            ['canary', 'amd64'] => Http::response(feedRelease('v0.1.2-canary.1', 'canary')),
            // Nothing for arm64 yet.
            default => Http::response(['message' => 'Not found'], 404),
        };
    });
    $this->mock(DaemonUpdates::class, fn ($mock) => $mock->shouldReceive('push')->once()->with('amd64')->andReturn(['offered' => [], 'failed' => [], 'waiting' => []]));

    $this->artisan('daemon:sync-releases')->assertSuccessful();

    expect(DaemonRelease::query()->count())->toBe(2)
        ->and(DaemonRelease::latest('stable', 'amd64')?->toCandidate())->toMatchArray([
            'version' => 'v0.1.1-pre.36',
            'url' => 'https://releases.serveros.com/v0.1.1-pre.36/serverosd-v0.1.1-pre.36-linux-amd64',
            'sha256' => hash('sha256', 'v0.1.1-pre.36'),
            'channel' => 'stable',
        ])
        ->and(DaemonRelease::latest('canary', 'amd64')?->version)->toBe('v0.1.2-canary.1')
        ->and(DaemonRelease::latest('stable', 'arm64'))->toBeNull();

    // The daemons' own update check reads them from this panel.
    $this->getJson('/api/daemon/releases/latest?channel=stable&arch=x86_64')
        ->assertOk()
        ->assertJsonPath('version', 'v0.1.1-pre.36');

    // Nothing new the second time: nothing offered again.
    $this->artisan('daemon:sync-releases')->assertSuccessful();
    expect(DaemonRelease::query()->count())->toBe(2);
});

test('an answer that isn’t a release is skipped, and an unreachable feed fails', function () {
    Http::fake([RELEASE_FEED.'*' => Http::response(['version' => 'latest', 'sha256' => 'nope'])]);
    $this->mock(DaemonUpdates::class, fn ($mock) => $mock->shouldNotReceive('push'));

    $this->artisan('daemon:sync-releases')->assertSuccessful();
    expect(DaemonRelease::query()->count())->toBe(0);

    Http::fake(fn () => throw new ConnectionException('down'));
    $this->artisan('daemon:sync-releases')->assertFailed();
});

test('the release feed is only copied on self-hosted installs', function () {
    $scheduled = fn () => collect(app(Schedule::class)->events())
        ->contains(fn ($event) => str_contains((string) $event->command, 'daemon:sync-releases'));

    expect($scheduled())->toBeFalse();
});
