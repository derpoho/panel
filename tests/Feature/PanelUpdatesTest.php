<?php

use App\Models\Activity;
use App\Models\DaemonRelease;
use App\Models\Machine;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Activity\ActivityLine;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\SelfHostedEntitlements;
use App\Support\Machines\MachinePolicies;
use App\Support\Updates\PanelUpdates;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const PANEL_RELEASES = 'https://api.github.com/repos/serveroshq/panel/releases/latest';

beforeEach(function () {
    $this->versionFile = tempnam(sys_get_temp_dir(), 'serveros-version');
    file_put_contents($this->versionFile, "1.3.2\n");
    config()->set('serveros.updates.version_file', $this->versionFile);
    config()->set('serveros.install.docker', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.release_feed_url', 'https://serveros.com/api/daemon/releases/latest');
    panelReleases('v1.4.0');
});

afterEach(function () {
    @unlink($this->versionFile);
});

function selfHostedPanel(): void
{
    config(['serveros.edition' => 'self-hosted']);
    app()->forgetInstance(Entitlements::class);
    app()->singleton(Entitlements::class, fn () => new SelfHostedEntitlements);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
}

function panelReleases(string $tag, bool $reachable = true): void
{
    Http::swap(new Factory);
    Http::fake([
        PANEL_RELEASES => $reachable
            ? Http::response(['tag_name' => $tag, 'html_url' => "https://github.com/serveroshq/panel/releases/tag/{$tag}", 'published_at' => '2026-11-13T09:00:00Z', 'draft' => false, 'prerelease' => false])
            : fn () => throw new ConnectionException('unreachable'),
        '127.0.0.1:8090/*' => Http::response(['ok' => true]),
        'serveros.com/api/daemon/*' => Http::response(['message' => 'Not found'], 404),
    ]);
}

function panelSettingsUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().'/settings/'.$path;
}

function updaterRuns(): void
{
    test()->artisan('panel:update-claim')->assertSuccessful();
}

function panelUpdateMachine(Workspace $workspace, string $name): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(), 'certificate_serial' => bin2hex(random_bytes(6)), 'daemon_version' => 'v0.1.1-pre.36',
        'last_seen_at' => now(), 'connected_at' => now(), 'facts' => ['hostname' => $name, 'arch' => 'x86_64'],
    ])->save();

    return $machine->fresh();
}

test('only a self-hosted install’s workspace owner sees updates or can start one', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(panelSettingsUrl($workspace, 'general'))
        ->assertInertia(fn (Assert $page) => $page->where('panelUpdate', null));
    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertNotFound();
    $this->actingAs($workspace->owner)->getJson(panelSettingsUrl($workspace, 'panel-update'))->assertNotFound();

    selfHostedPanel();
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)->get(panelSettingsUrl($workspace, 'general'))
        ->assertInertia(fn (Assert $page) => $page->where('panelUpdate', null));
    $this->actingAs($admin)->post(panelSettingsUrl($workspace, 'panel-update'))->assertNotFound();
    $this->actingAs($admin)->post(panelSettingsUrl($workspace, 'panel-update/check'))->assertNotFound();
    expect(PanelUpdate::query()->count())->toBe(0);
});

test('someone who signs up later owns a workspace but can’t update the install, unless made an admin', function () {
    selfHostedPanel();
    $workspace = Workspace::factory()->create();
    WorkspaceInvitation::factory()->for($workspace)->create(['email' => 'vic@example.com', 'role' => 'viewer']);
    $this->post(route('register.store'), ['name' => 'Vic', 'email' => 'vic@example.com', 'password' => 'Str0ng!pass-xyz-123', 'password_confirmation' => 'Str0ng!pass-xyz-123'])->assertSessionHasNoErrors();
    $vic = User::query()->where('email', 'vic@example.com')->firstOrFail();
    $vic->forceFill(['email_verified_at' => now()])->save();
    $own = Workspace::query()->where('owner_id', $vic->id)->firstOrFail();
    updaterRuns();
    PanelUpdates::check();

    $this->actingAs($vic)->get(panelSettingsUrl($own, 'general'))
        ->assertInertia(fn (Assert $page) => $page->where('panelUpdate', null)->where('githubSetup.canSetUp', false));
    $this->actingAs($vic)->post(panelSettingsUrl($own, 'panel-update'))->assertNotFound();
    expect(PanelUpdate::query()->count())->toBe(0);

    $vic->forceFill(['is_admin' => true])->save();
    $this->actingAs($vic)->post(panelSettingsUrl($own, 'panel-update'))->assertSessionHasNoErrors();
    expect(PanelUpdate::query()->sole()->requested_by)->toBe($vic->id);
});

test('checking finds a newer release on GitHub, and an unreachable GitHub keeps the last answer', function () {
    selfHostedPanel();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(panelSettingsUrl($workspace, 'general'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panelUpdate.current', '1.3.2')
            ->where('panelUpdate.latest', null)
            ->where('panelUpdate.available', false)
            ->where('panelUpdate.checkedAt', null));
    Http::assertNotSent(fn (Request $request) => $request->url() === PANEL_RELEASES);

    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update/check'))->assertRedirect();
    $this->actingAs($workspace->owner)->get(panelSettingsUrl($workspace, 'general'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panelUpdate.latest', ['version' => '1.4.0', 'url' => 'https://github.com/serveroshq/panel/releases/tag/v1.4.0', 'publishedAt' => '2026-11-13T09:00:00Z'])
            ->where('panelUpdate.available', true)
            ->where('panelUpdate.reached', true));

    panelReleases('v1.4.0', reachable: false);
    $this->artisan('panel:check-updates')->assertFailed();
    expect(PanelUpdates::lastCheck())->toMatchArray(['reached' => false])
        ->and(PanelUpdates::available()['version'] ?? null)->toBe('1.4.0');

    panelReleases('v1.3.2');
    $this->artisan('panel:check-updates')->assertSuccessful();
    expect(PanelUpdates::available())->toBeNull();

    file_put_contents($this->versionFile, "dev\n");
    panelReleases('v9.0.0');
    $this->artisan('panel:check-updates')->assertSuccessful();
    expect(PanelUpdates::available())->toBeNull();
});

test('the release check runs every hour on self-hosted installs only', function () {
    $scheduled = fn () => collect(app(Schedule::class)->events())
        ->contains(fn ($event) => str_contains((string) $event->command, 'panel:check-updates'));

    expect($scheduled())->toBeFalse();
});

test('an update can only start once the server runs the updater, and one at a time', function () {
    selfHostedPanel();
    $workspace = Workspace::factory()->create();
    PanelUpdates::check();

    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasErrors('update');
    expect(PanelUpdate::query()->count())->toBe(0);

    Cache::forever(PanelUpdates::UPDATER_SEEN, (string) now()->subMinutes(2)->getTimestamp());
    expect(PanelUpdates::updaterSeen())->toBeTrue();
    Cache::forget(PanelUpdates::UPDATER_SEEN);

    updaterRuns();
    config()->set('serveros.install.docker', false);
    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasErrors('update');

    config()->set('serveros.install.docker', true);
    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasErrors('update');

    expect(PanelUpdate::query()->sole())->toMatchArray(['version' => '1.4.0', 'from_version' => '1.3.2', 'status' => 'requested', 'workspace_id' => $workspace->id, 'requested_by' => $workspace->owner->id])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'panel.update')->sole()))->toContain('started updating ServerOS to 1.4.0');
});

test('the updater claims the update, and once the panel is back on it the workspace’s machines get the newest daemon', function () {
    selfHostedPanel();
    $workspace = Workspace::factory()->create();
    MachinePolicies::defaultFor($workspace)->update(['updates' => 'approve']);
    $machine = panelUpdateMachine($workspace, 'web-1');
    panelUpdateMachine(Workspace::factory()->create(), 'elsewhere');
    DaemonRelease::query()->create(['version' => 'v0.1.1-pre.37', 'arch' => 'amd64', 'channel' => 'stable', 'sha256' => str_repeat('a', 64), 'signature' => base64_encode(str_repeat('s', 64))]);
    PanelUpdates::check();
    updaterRuns();
    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasNoErrors();

    $update = PanelUpdate::query()->sole();
    $this->artisan('panel:update-claim')->expectsOutput("{$update->id} 1.4.0")->assertSuccessful();
    $this->artisan('panel:update-claim')->doesntExpectOutput("{$update->id} 1.4.0")->assertSuccessful();
    expect($update->fresh()->status)->toBe('running');

    $this->actingAs($workspace->owner)->getJson(panelSettingsUrl($workspace, 'panel-update'))
        ->assertOk()
        ->assertJsonPath('update.status', 'running')
        ->assertJsonPath('update.version', '1.4.0');

    file_put_contents($this->versionFile, "1.4.0\n");
    $this->artisan('panel:update-finished', ['update' => $update->id])->assertSuccessful();

    expect($update->fresh())->status->toBe('done')->finished_at->not->toBeNull();
    $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/control') && ($r->data()['control']['type'] ?? null) === 'self_update');
    expect($sent)->toHaveCount(1)
        ->and(str_contains($sent->first()->url(), $machine->uid))->toBeTrue()
        ->and($sent->first()->data()['control'])->toMatchArray(['version' => 'v0.1.1-pre.37', 'approved' => true])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'panel.updated')->sole()))->toBe('ServerOS is on 1.4.0, updated from 1.3.2')
        ->and(Activity::query()->where('action', 'machine.daemon_update')->sole()->subject)->toBe('web-1');

    $this->actingAs($workspace->owner)->get(panelSettingsUrl($workspace, 'general'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('panelUpdate.current', '1.4.0')
            ->where('panelUpdate.available', false)
            ->where('panelUpdate.update.status', 'done')
            ->where('panelUpdate.update.from', '1.3.2'));
});

test('an update that fails, comes back on the old version or never finishes says so', function () {
    selfHostedPanel();
    $workspace = Workspace::factory()->create();
    PanelUpdates::check();
    updaterRuns();

    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'));
    $failed = PanelUpdate::query()->sole();
    updaterRuns();
    $this->artisan('panel:update-finished', ['update' => $failed->id, '--failed' => 'The update stopped partway.'])->assertSuccessful();
    expect($failed->fresh())->status->toBe('failed')->error->toBe('The update stopped partway.')
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'panel.update_failed')->sole()))->toBe('ServerOS couldn’t update to 1.4.0');
    $this->artisan('panel:update-finished', ['update' => $failed->id])->assertFailed();

    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasNoErrors();
    $behind = PanelUpdate::query()->latest('id')->first();
    updaterRuns();
    $this->artisan('panel:update-finished', ['update' => $behind->id])->assertSuccessful();
    expect($behind->fresh())->status->toBe('failed')->error->toContain('came back on 1.3.2');

    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasNoErrors();
    $stuck = PanelUpdate::query()->latest('id')->first();
    updaterRuns();
    $this->travel(31)->minutes();
    $this->actingAs($workspace->owner)->getJson(panelSettingsUrl($workspace, 'panel-update'))
        ->assertJsonPath('update.status', 'failed')
        ->assertJsonPath('updater', false);
    expect($stuck->fresh()->error)->toContain('/var/log/serveros-panel-update.log');
});

test('the install’s first account sees an update on the home dashboard until one is on its way', function () {
    selfHostedPanel();
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'admin']);
    $home = 'https://'.$workspace->host().'/';

    $this->actingAs($workspace->owner)->get($home)->assertInertia(fn (Assert $page) => $page->where('panelUpdate', null));

    PanelUpdates::check();
    updaterRuns();
    $this->actingAs($workspace->owner)->get($home)
        ->assertInertia(fn (Assert $page) => $page->where('panelUpdate', ['version' => '1.4.0', 'current' => '1.3.2']));
    $this->actingAs($member)->get($home)->assertInertia(fn (Assert $page) => $page->where('panelUpdate', null));

    $this->actingAs($workspace->owner)->post(panelSettingsUrl($workspace, 'panel-update'))->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->get($home)->assertInertia(fn (Assert $page) => $page->where('panelUpdate', null));
});
