<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Deploys\DeployRequest;
use App\Support\Domains\DomainResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * An app deployed from Git on acme.io, on a machine whose daemon is
 * :version, with the gateway faked.
 *
 * @param  list<string>  $domains
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function wildcardApp(array $domains = ['acme.io'], string $version = '0.1.1-pre.34'): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version])->save();

    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'crm',
        'state' => 'succeeded', 'log' => [],
        'command' => ['job' => [
            'type' => 'deploy', 'service' => 'crm', 'repo' => 'https://github.com/acme/crm.git',
            'commit' => str_repeat('a', 40), 'env' => ['APP_URL' => 'https://acme.io'],
            'domains' => $domains, 'port' => 3000,
        ]],
    ]);

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

/** The deploy job the gateway was sent. */
function sentDeploy(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/commands'))
        ->map(fn (array $pair) => json_decode($pair[0]->body(), true)['command']['job'])
        ->sole();
}

test('a wildcard deploy serves the domain and every subdomain under it', function () {
    expect(DeployRequest::domains('Acme.io', true))->toBe(['acme.io', '*.acme.io'])
        ->and(DeployRequest::domains('acme.io'))->toBe(['acme.io'])
        ->and(DeployRequest::domains(null, true))->toBe([])
        ->and(DeployRequest::isWildcard(['acme.io', '*.acme.io']))->toBeTrue()
        ->and(DeployRequest::isWildcard(['acme.io', '*.other.io']))->toBeFalse()
        ->and(DeployRequest::isWildcard([]))->toBeFalse()
        ->and(DeployRequest::plainDomains(['acme.io', '*.acme.io']))->toBe(['acme.io']);

    $job = DeployRequest::gitJob([
        'service' => 'crm', 'repo' => 'https://github.com/acme/crm.git', 'commit' => str_repeat('a', 40),
        'port' => 3000, 'health_path' => '/', 'domain' => 'acme.io', 'wildcard' => true,
    ]);
    expect($job['domains'])->toBe(['acme.io', '*.acme.io']);
});

test('ticking wildcard on the same domain redeploys with every subdomain, leaving settings alone', function () {
    [$workspace, , $url] = wildcardApp();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/crm/domain', ['domain' => 'acme.io', 'wildcard' => true, 'move_settings' => false])
        ->assertAccepted();

    $job = sentDeploy();
    expect($job['domains'])->toBe(['acme.io', '*.acme.io'])
        ->and($job['env']['APP_URL'])->toBe('https://acme.io');
});

test('moving a wildcard app keeps its subdomains and moves only the plain domain in settings', function () {
    [$workspace, , $url] = wildcardApp(['acme.io', '*.acme.io']);

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/crm/domain', ['domain' => 'acmecrm.io', 'move_settings' => true])
        ->assertAccepted();

    $job = sentDeploy();
    expect($job['domains'])->toBe(['acmecrm.io', '*.acmecrm.io'])
        ->and($job['env']['APP_URL'])->toBe('https://acmecrm.io');
});

test('unticking wildcard serves only the domain again, and the same setting twice is refused', function () {
    [$workspace, , $url] = wildcardApp(['acme.io', '*.acme.io']);

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/crm/domain', ['domain' => 'acme.io', 'wildcard' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('domain');

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/crm/domain', ['domain' => 'acme.io', 'wildcard' => false])
        ->assertAccepted();

    expect(sentDeploy()['domains'])->toBe(['acme.io']);
});

test('a daemon too old for wildcards is told to update, and nothing is sent', function () {
    [$workspace, , $url] = wildcardApp(['acme.io'], '0.1.1-pre.33');

    $this->actingAs($workspace->owner)
        ->postJson($url.'/apps/crm/domain', ['domain' => 'acme.io', 'wildcard' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['wildcard' => 'too old for wildcard subdomains']);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/commands'));
});

test('the DNS check says whether every subdomain points at the machine too', function () {
    [$workspace, , $url] = wildcardApp();

    // Only a *.acme.io record makes a made-up subdomain resolve.
    app()->instance(DomainResolver::class, new class extends DomainResolver
    {
        public function addresses(string $domain): array
        {
            return match (true) {
                $domain === 'acme.io' => ['203.0.113.7'],
                str_starts_with($domain, 'serveros-check-') && str_ends_with($domain, '.acme.io') => ['198.51.100.9'],
                default => [],
            };
        }
    });

    $this->actingAs($workspace->owner)
        ->postJson($url.'/domain-check', ['domain' => 'acme.io', 'wildcard' => true])
        ->assertOk()
        ->assertJson([
            'status' => 'ready',
            'wildcard' => [
                'domain' => '*.acme.io',
                'status' => 'elsewhere',
                'record' => ['type' => 'A', 'name' => '*.acme.io', 'value' => '203.0.113.7'],
            ],
        ]);

    $this->actingAs($workspace->owner)
        ->postJson($url.'/domain-check', ['domain' => 'acme.io'])
        ->assertOk()
        ->assertJsonMissingPath('wildcard');
});
