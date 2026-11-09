<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Domains\DomainResolver;

/**
 * Answer DNS lookups from a fixed table instead of the network.
 *
 * @param  array<string, list<string>>  $records
 */
function fakeDns(array $records): void
{
    app()->instance(DomainResolver::class, new class($records) extends DomainResolver
    {
        /** @param  array<string, list<string>>  $records */
        public function __construct(private array $records) {}

        public function addresses(string $domain): array
        {
            return $this->records[$domain] ?? [];
        }
    });
}

/**
 * @return array{0: Workspace, 1: string}
 */
function domainCheck(): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['ip_address' => '203.0.113.7']);

    return [$workspace, 'https://'.$workspace->host().'/machines/'.$machine->id.'/domain-check'];
}

test('a domain is ready once its A record points at the machine', function () {
    [$workspace, $url] = domainCheck();
    fakeDns(['blog.example.com' => ['203.0.113.7']]);

    $this->actingAs($workspace->owner)->postJson($url, ['domain' => 'Blog.Example.com'])
        ->assertOk()
        ->assertJson([
            'domain' => 'blog.example.com',
            'status' => 'ready',
            'expected' => '203.0.113.7',
            'record' => ['type' => 'A', 'name' => 'blog.example.com', 'value' => '203.0.113.7'],
        ]);
});

test('a missing, misdirected or Cloudflare-proxied domain says so', function () {
    [$workspace, $url] = domainCheck();
    fakeDns([
        'elsewhere.example.com' => ['198.51.100.9'],
        'proxied.example.com' => ['104.21.3.4'],
    ]);
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson($url, ['domain' => 'new.example.com'])
        ->assertOk()->assertJson(['status' => 'missing', 'found' => []]);

    $this->actingAs($owner)->postJson($url, ['domain' => 'elsewhere.example.com'])
        ->assertOk()->assertJson(['status' => 'elsewhere', 'found' => ['198.51.100.9']]);

    $this->actingAs($owner)->postJson($url, ['domain' => 'proxied.example.com'])
        ->assertOk()->assertJson(['status' => 'proxied']);

    $this->actingAs($owner)->postJson($url, ['domain' => 'not a domain'])
        ->assertUnprocessable()->assertJsonValidationErrors(['domain']);
});

test('viewers cannot check domains', function () {
    [$workspace, $url] = domainCheck();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->postJson($url, ['domain' => 'blog.example.com'])->assertForbidden();
});
