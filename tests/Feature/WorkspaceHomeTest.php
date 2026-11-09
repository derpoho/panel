<?php

use App\Enums\MachineEnvironment;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\UpgradeLink;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function homeUrl(Workspace $workspace): string
{
    return 'https://'.$workspace->host().'/';
}

test('a new workspace sees onboarding with the first machine as the next step', function () {
    $workspace = Workspace::factory()->create();
    // Somewhere to choose a plan, as a billing package adds.
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Home')
            ->where('workspace.host', $workspace->host())
            ->where('onboardingDismissed', false)
            ->where('stats.machines', 0)
            ->where('stats.averageCpu', 0)
            ->where('providers', [])
            ->where('onboarding', [
                ['key' => 'workspace', 'done' => true],
                ['key' => 'machine', 'done' => false],
                ['key' => 'service', 'done' => false],
                ['key' => 'backups', 'done' => false],
                ['key' => 'plan', 'done' => false],
            ])
            ->has('issues', 0));
});

test('connected machines turn onboarding into fleet stats', function () {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create(['services_count' => 3, 'cpu_percent' => 20]);
    Machine::factory()->for($workspace)->busy()->create(['services_count' => 2]);
    Machine::factory()->for($workspace)->environment(MachineEnvironment::Staging)->neverBackedUp()
        ->create(['services_count' => 1, 'cpu_percent' => 40]);
    Machine::factory()->create(['cpu_percent' => 99]);

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.1.done', true)
            ->where('onboarding.3.done', true)
            ->where('stats', [
                'machines' => 3,
                'environments' => ['production' => 2, 'staging' => 1, 'development' => 0],
                'services' => 6,
                'averageCpu' => 51,
                'busy' => 1,
                'backedUp' => 2,
                'cpuHistory' => array_fill(0, 12, null),
            ]));
});

test('the CPU tile gets the last hour in 5-minute steps, averaged across machines', function () {
    $this->travelTo(now()->setTimestamp(1_800_000_000));
    $workspace = Workspace::factory()->create();
    [$a, $b] = Machine::factory()->count(2)->for($workspace)->create();
    $other = Machine::factory()->create();
    $now = now()->getTimestamp();
    $sample = fn (Machine $machine, int $ago, float $cpu) => MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => $now - $ago, 'samples' => 1, 'cpu' => $cpu, 'cpu_max' => $cpu]);
    $sample($a, 0, 10);
    $sample($b, 0, 30);
    $sample($a, 2 * 300, 70);
    $sample($other, 0, 99);
    $sample($a, 2 * 3600, 50);

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('stats.cpuHistory', [null, null, null, null, null, null, null, null, null, 70, null, 20]));
});

test('what needs attention is listed worst first, in plain words', function () {
    $workspace = Workspace::factory()->create();
    $connected = fn (array $attrs) => tap(Machine::factory()->for($workspace)->create(['environment' => 'production', 'last_backup_at' => now()]))
        ->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), ...$attrs])->save();
    $connected(['name' => 'healthy', 'disks' => [['mount' => '/', 'used' => 10 << 30, 'total' => 80 << 30]], 'disk_percent' => 12]);
    $connected(['name' => 'gone', 'last_seen_at' => now()->subMinutes(10)]);
    $connected(['name' => 'full', 'disks' => [['mount' => '/', 'used' => 76 << 30, 'total' => 80 << 30]], 'disk_percent' => 95]);
    $connected(['name' => 'filling', 'disks' => [['mount' => '/var/lib/docker', 'used' => 70 << 30, 'total' => 80 << 30]], 'disk_percent' => 87, 'backup_schedule' => 'daily', 'last_backup_at' => now()->subDays(3)]);
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'status' => 'down', 'last_error' => 'Returned 502.', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status_changed_at' => now()->subMinutes(2), 'ssl_expires_at' => now()->addDays(5)]);

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('issues', fn ($issues) => collect($issues)->pluck('title')->all() === [
                'Shop is down',
                'gone stopped reporting',
                'full is 95% full',
                'filling is 87% full',
                'Shop’s certificate expires in 4 days',
                'filling hasn’t been backed up since 3 days ago',
            ])
            ->where('issues.2.detail', fn ($d) => str_contains($d, 'Its main disk has 4 GB left'))
            ->where('issues.3.detail', fn ($d) => str_contains($d, '/var/lib/docker'))
            ->where('issues.0.href', '/monitors/'.$workspace->monitors()->value('id')));
});

test('telemetry keeps real disks and how full the fullest one is', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $disk = fn ($mount, $used, $free) => ['mount' => $mount, 'used_bytes' => $used, 'free_bytes' => $free, 'inodes_used' => 0, 'inodes_free' => 0, 'read_bytes' => 0, 'write_bytes' => 0];

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [[
        'ts' => time(), 'cpu_percent' => 5, 'mem_used' => 1 << 30,
        'disks' => [$disk('/', 30 << 30, 50 << 30), $disk('/data', 90 << 30, 10 << 30), $disk('/snap/core/1', 1 << 30, 0), $disk('/boot', 100 << 20, 400 << 20)],
    ]]]]]], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $machine->refresh();
    expect($machine->disk_percent)->toBe(90)
        ->and(array_column($machine->disks, 'mount'))->toBe(['/data', '/']);
});

test('the setup guide can be skipped and resumed per person', function () {
    $workspace = Workspace::factory()->create();
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($workspace->owner)
        ->post(homeUrl($workspace).'onboarding/dismiss')
        ->assertRedirect();

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('onboardingDismissed', true));

    $this->actingAs($teammate)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('onboardingDismissed', false));

    $this->actingAs($workspace->owner)->delete(homeUrl($workspace).'onboarding/dismiss');

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('onboardingDismissed', false));
});

test('outsiders cannot skip a workspace setup guide', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(homeUrl($workspace).'onboarding/dismiss')
        ->assertForbidden();
});

test('machines are broken down by provider, largest first', function () {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->count(2)->create(['provider' => 'Hetzner']);
    Machine::factory()->for($workspace)->create(['provider' => 'Vultr']);

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('providers', [
            ['provider' => 'Hetzner', 'machines' => 2],
            ['provider' => 'Vultr', 'machines' => 1],
        ]));
});

test('home includes member count, last backup and a few machines', function () {
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach(User::factory()->create(), ['role' => 'admin']);
    Machine::factory()->for($workspace)->create(['last_backup_at' => now()->subHours(3)]);
    Machine::factory()->for($workspace)->create(['last_backup_at' => now()->subMinutes(5)]);

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('memberCount', 2)
            ->where('lastBackup', '5 minutes ago')
            ->has('machines', 2));
});
