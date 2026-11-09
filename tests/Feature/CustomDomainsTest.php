<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDomain;
use App\Models\WorkspaceStatusPage;
use App\Support\Auth\GoogleTeamAccess;
use App\Support\Domains\CustomDomains;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SignInHooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.cloudflare.token', 'cf-token');
    config()->set('services.cloudflare.zone_id', 'zone-1');
    config()->set('services.cloudflare.cname_target', 'customers.serveros.com');
    CustomDomains::forget();
    cfLive([]);

    // Cloudflare: hostnames start pending, and are active once cfLive says so.
    Http::fake(['api.cloudflare.com/*' => function ($request) {
        $hostname = $request->method() === 'POST' ? $request['hostname'] : str_replace('cf-', '', basename($request->url()));
        if ($request->method() === 'DELETE') {
            return Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'cf-'.$hostname]]);
        }
        $live = in_array($hostname, cfLive(), true);

        return Http::response(cfHostname($hostname, $live ? 'active' : 'pending', $live ? 'active' : 'pending_validation'));
    }]);
});

/**
 * The hostnames Cloudflare has made live.
 *
 * @param  list<string>|null  $set
 * @return list<string>
 */
function cfLive(?array $set = null): array
{
    static $live = [];
    if ($set !== null) {
        $live = $set;
    }

    return $live;
}

/** Cloudflare's answer for a custom hostname. */
function cfHostname(string $hostname, string $status = 'pending', string $ssl = 'pending_validation'): array
{
    return ['success' => true, 'errors' => [], 'result' => [
        'id' => 'cf-'.$hostname,
        'hostname' => $hostname,
        'status' => $status,
        'ssl' => ['status' => $ssl, 'validation_errors' => []],
        'ownership_verification' => ['type' => 'txt', 'name' => '_cf-custom-hostname.'.$hostname, 'value' => 'abc-123'],
        'verification_errors' => $status === 'active' ? [] : ['custom hostname does not CNAME to this zone.'],
    ]];
}

/** @return array{0: Workspace, 1: string} */
function domainWorkspace(Plan $plan = Plan::Business, string $slug = 'acme'): array
{
    $workspace = Workspace::factory()->onPlan($plan)->create(['slug' => $slug]);

    return [$workspace, 'https://'.$workspace->subdomain().'/settings/domains'];
}

/** Connect and activate a dashboard domain the way Cloudflare would. */
function liveDomain(Workspace $workspace, string $hostname = 'ops.acme.test', string $purpose = WorkspaceDomain::DASHBOARD): WorkspaceDomain
{
    $domains = app(CustomDomains::class);
    $domain = $domains->connect($workspace, $purpose, $hostname);
    cfLive([...cfLive(), $hostname]);
    $domains->refresh($domain);

    return $domain->fresh();
}

test('connecting a domain asks Cloudflare and shows the records to add', function () {
    [$workspace, $url] = domainWorkspace();
    $this->actingAs($workspace->owner)->post($url, ['purpose' => 'dashboard', 'hostname' => 'https://OPS.acme.test/'])->assertRedirect();

    $domain = WorkspaceDomain::query()->sole();
    expect($domain->hostname)->toBe('ops.acme.test')
        ->and($domain->state)->toBe('pending')
        ->and($domain->verification['records'])->toBe([
            ['type' => 'CNAME', 'name' => 'ops.acme.test', 'value' => 'customers.serveros.com'],
            ['type' => 'TXT', 'name' => '_cf-custom-hostname.ops.acme.test', 'value' => 'abc-123'],
        ]);
    Http::assertSent(fn ($request) => $request['hostname'] === 'ops.acme.test' && $request['ssl']['method'] === 'http' && $request->hasHeader('Authorization', 'Bearer cf-token'));

    $this->actingAs($workspace->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/settings/Domains')
        ->where('dashboard.state', 'pending')
        ->where('dashboard.records.0.value', 'customers.serveros.com')
        ->where('allowed', true));
});

test('only Business workspaces, only subdomains, never ours', function () {
    [$pro, $proUrl] = domainWorkspace(Plan::Pro, 'acme-pro');
    $this->actingAs($pro->owner)->post($proUrl, ['purpose' => 'dashboard', 'hostname' => 'ops.acme.test'])->assertSessionHasErrors('hostname');

    [$workspace, $url] = domainWorkspace();
    foreach (['acme.test', 'x.'.config('app.domain'), 'not a domain', '10.0.0.1'] as $bad) {
        $this->actingAs($workspace->owner)->post($url, ['purpose' => 'dashboard', 'hostname' => $bad])->assertSessionHasErrors('hostname');
    }
    expect(WorkspaceDomain::query()->count())->toBe(0);
});

test('a live domain serves the dashboard, and links point at it, except MCP', function () {
    [$workspace] = domainWorkspace();
    $domain = liveDomain($workspace);

    expect($domain->state)->toBe('active')
        ->and($domain->verification)->toBeNull()
        ->and($workspace->host())->toBe('ops.acme.test')
        ->and(route('workspace.machines.index', ['workspace' => 'acme']))->toBe('https://ops.acme.test/machines')
        ->and(route('mcp', ['workspace' => 'acme']))->toStartWith('https://acme.'.config('app.domain'))
        ->and(Activity::query()->pluck('action')->all())->toContain('domain.add', 'domain.active');

    $this->actingAs($workspace->owner)->get('https://ops.acme.test/machines')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Machines')->where('workspace.host', 'ops.acme.test'));

    // Signed out, it signs in on the same domain.
    auth()->logout();
    $this->get('https://ops.acme.test/machines')->assertRedirect('https://ops.acme.test/login');

    // Someone else's domain shows nothing of this workspace.
    $this->get('https://elsewhere.acme.test/machines')->assertNotFound();
});

test('the old subdomain hands people over to the domain, signed in', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $sub = 'https://'.$workspace->subdomain();

    $this->actingAs($workspace->owner)->get("{$sub}/machines")->assertRedirect("{$sub}/domains/go?to=%2Fmachines");
    $go = $this->actingAs($workspace->owner)->get("{$sub}/domains/go?to=/machines")->assertRedirect()->headers->get('Location');
    expect($go)->toStartWith('https://ops.acme.test/_domain/handoff/');

    auth()->logout();
    $this->get($go)->assertRedirect('/machines');
    $this->assertAuthenticatedAs($workspace->owner);

    // Each link works once.
    auth()->logout();
    $this->get($go)->assertRedirect('/login');
    $this->assertGuest();
});

test('the hand-off carries what a package adds to it, and gives it back there', function () {
    [$workspace] = domainWorkspace();
    liveDomain($workspace);
    $member = User::factory()->create(['email' => 'ada@acme.com']);
    $workspace->members()->attach($member, ['role' => 'member']);
    $sub = 'https://'.$workspace->subdomain();
    // Like a package's SSO: something from the session, back in the session there.
    app(Extensions::class)->addSignInHooks(new class extends SignInHooks
    {
        public function handoff(Request $request): array
        {
            return ['ledger' => $request->session()->get('ledger')];
        }

        public function receiveHandoff(Request $request, array $carried, User $user): void
        {
            $request->session()->put('ledger', $carried['ledger'].' for '.$user->email);
        }
    });

    $go = $this->actingAs($member)
        ->withSession([GoogleTeamAccess::VIA => 'google', 'ledger' => 'kept'])
        ->get("{$sub}/domains/go?to=/machines")->headers->get('Location');
    auth()->logout();
    session()->flush();

    $this->get($go)->assertRedirect('/machines');
    expect(session(GoogleTeamAccess::VIA))->toBe('google')
        ->and(session('ledger'))->toBe('kept for ada@acme.com');
});

test('a status page can have its own hostname, or a path on the dashboard’s domain', function () {
    [$workspace] = domainWorkspace();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme-status', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    liveDomain($workspace);

    // On a path of the dashboard's domain.
    app(CustomDomains::class)->connect($workspace, WorkspaceDomain::STATUS, null, 'uptime');
    $this->get('https://ops.acme.test/uptime')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('status/Show')
        ->where('status.title', $workspace->name)
        ->url('/uptime')
        ->where('jsonUrl', 'https://ops.acme.test/uptime/status.json'));
    $this->get('https://ops.acme.test/uptime/status.json')->assertOk()->assertJsonPath('title', $workspace->name);

    // Or a hostname of its own.
    liveDomain($workspace, 'status.acme.test', WorkspaceDomain::STATUS);
    $this->get('https://status.acme.test/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('status/Show'));
    $this->get('https://status.acme.test/badge.svg')->assertOk();
    // The path went when the hostname came.
    $this->get('https://ops.acme.test/uptime')->assertNotFound();
});

test('Caddy may get certificates for ours and for connected domains only', function () {
    [$workspace] = domainWorkspace();
    app(CustomDomains::class)->connect($workspace, WorkspaceDomain::DASHBOARD, 'ops.acme.test');
    $ask = fn (string $domain, string $ip = '127.0.0.1') => $this->call('GET', '/api/internal/tls-check', ['domain' => $domain], [], [], ['REMOTE_ADDR' => $ip]);

    expect($ask('ops.acme.test')->status())->toBe(200)
        ->and($ask('acme.'.config('app.domain'))->status())->toBe(200)
        ->and($ask('evil.test')->status())->toBe(404)
        ->and($ask('ops.acme.test', '203.0.113.9')->status())->toBe(404);
});

test('disconnecting removes the hostname from Cloudflare, and the subdomain is home again', function () {
    [$workspace, $url] = domainWorkspace();
    $domain = liveDomain($workspace);

    $this->actingAs($workspace->owner)->delete('https://ops.acme.test/settings/domains/'.$domain->id)
        ->assertRedirect('https://acme.'.config('app.domain').'/settings/domains');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/custom_hostnames/'.$domain->cloudflare_id));
    expect(WorkspaceDomain::query()->count())->toBe(0)
        ->and($workspace->host())->toBe('acme.'.config('app.domain'));
});

test('status.{our domain} serves our own status page, when one is set', function () {
    [$workspace] = domainWorkspace();
    $fleet = new WorkspaceStatusPage(['slug' => 'serveros-qbml', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    $host = 'https://status.'.config('app.domain');

    // Unset, it's just a reserved workspace name.
    $this->get("{$host}/")->assertRedirect();

    config()->set('serveros.status_page', 'serveros-qbml');
    $this->get("{$host}/")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('status/Show')
        ->url('/')
        ->where('jsonUrl', "{$host}/status.json")
        ->where('meta.image', fn (string $image) => str_starts_with($image, "{$host}/og.png?v=")));
    $this->get("{$host}/status.json")->assertOk()->assertJsonPath('title', $workspace->name);
    $this->get("{$host}/status/serveros-qbml/badge.svg")->assertOk();
});

test('a private status page on its own hostname says so there, with a way to sign in on ours, and settings warn about it', function () {
    [$workspace, $url] = domainWorkspace();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme-status', 'enabled' => true, 'public' => false, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    liveDomain($workspace, 'status.acme.test', WorkspaceDomain::STATUS);

    // There's no sign-in on the status domain: it says the page is private,
    // and links to the page on ours, which signs them in.
    $this->get('https://status.acme.test/')
        ->assertForbidden()
        ->assertSee('This status page is private')
        ->assertSee($workspace->name)
        ->assertSee(rtrim(config('app.url'), '/').'/status/acme-status', false);
    $this->get('https://'.config('app.domain').'/status/acme-status')->assertRedirect();

    // Settings › Domains knows the page is members only, and where to change it.
    $this->actingAs($workspace->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.hostname', 'status.acme.test')
        ->where('statusPages.0.fleet', true)
        ->where('statusPages.0.public', false)
        ->where('statusPages.0.settingsUrl', 'https://acme.'.config('app.domain').'/settings/status-page'));
});
