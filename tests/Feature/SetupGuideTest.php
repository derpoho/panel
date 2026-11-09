<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\UpgradeLink;
use Inertia\Testing\AssertableInertia as Assert;

function guideUrl(Workspace $workspace, string $path = '/'): string
{
    return 'https://'.$workspace->host().$path;
}

/** @return array<string, bool> step key => done */
function guideSteps(array $guide): array
{
    return collect($guide['groups'])->flatMap(fn (array $group) => collect($group['steps'])->pluck('done', 'key'))->all();
}

test('a new workspace gets the guide on every page, with the plan locked', function () {
    $workspace = Workspace::factory()->create();
    // Somewhere to choose a plan, as a billing package adds.
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace, '/machines'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('setupGuide.percent', 0)
            ->where('setupGuide.groups.3.key', 'plan')
            ->where('setupGuide.groups.3.locked', 'Unlocks once a machine is connected')
            ->where('setupGuide.groups.0.locked', null));
});

test('steps tick off as the workspace fills in', function () {
    $workspace = Workspace::factory()->create();
    // Somewhere to choose a plan, as a billing package adds.
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));
    // One service adopted: a scan finding some isn't enough to tick it off.
    Machine::factory()->for($workspace)->create(['services_count' => 2, 'last_backup_at' => null,
        'inventory' => ['services' => [['name' => 'nginx', 'details' => ['managed' => 'true']], ['name' => 'redis', 'details' => []]]]]);
    $workspace->members()->attach(User::factory()->create(), ['role' => 'admin']);

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace))
        ->assertInertia(function (Assert $page) {
            $guide = $page->toArray()['props']['setupGuide'];

            expect(guideSteps($guide))->toMatchArray([
                'machine' => true,
                'service' => true,
                'monitor' => false,
                'backups' => false,
                'members' => true,
                'security' => false,
                'plan' => false,
            ])->and($guide['percent'])->toBe(42)
                ->and($guide['groups'][3]['locked'])->toBeNull();
        });
});

test('hiding the guide puts it away for that person only', function () {
    $workspace = Workspace::factory()->create();
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($workspace->owner)->post(guideUrl($workspace, '/onboarding/dismiss'));

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('setupGuide', null));
    $this->actingAs($teammate)
        ->get(guideUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->whereNot('setupGuide', null));
});

test('pages outside a workspace have no guide', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertInertia(fn (Assert $page) => $page->where('setupGuide', null));
});
