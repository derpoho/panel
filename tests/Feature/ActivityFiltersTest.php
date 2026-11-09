<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityGroups;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

const CHROME_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

/** @param  array<string, mixed>  $attributes */
function logged(Workspace $workspace, string $action, array $attributes = []): Activity
{
    return Activity::query()->create(['workspace_id' => $workspace->id, 'action' => $action, ...$attributes]);
}

/** Something ServerOS Support did in the workspace, viewing as its owner. */
function loggedBySupport(Workspace $workspace, string $action, array $attributes = []): Activity
{
    $boss = User::factory()->create(['name' => 'Boss', 'email' => 'boss@serveros.com']);
    $session = ImpersonationSession::query()->create([
        'admin_id' => $boss->id, 'user_id' => $workspace->owner_id,
        'admin_name' => 'Boss', 'admin_email' => 'boss@serveros.com',
        'user_name' => $workspace->owner->name, 'user_email' => $workspace->owner->email,
        'reason' => 'Helping', 'started_at' => now(), 'last_seen_at' => now(),
    ]);

    return logged($workspace, $action, ['impersonation_session_id' => $session->id, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function shownActions(Workspace $workspace, array $query = [], ?User $as = null): array
{
    $actions = [];
    test()->actingAs($as ?? $workspace->owner)
        ->get('https://'.$workspace->host().'/activity'.($query ? '?'.http_build_query($query) : ''))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$actions) {
            $actions = array_column($page->toArray()['props']['entries'], 'action');
        });

    return $actions;
}

test('Activity filters by person, including people who left and accounts that are gone', function () {
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Ada'])->save();
    $grace = User::factory()->create(['name' => 'Grace']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $workspace->members()->attach($grace, ['role' => 'admin']);

    logged($workspace, 'service.restart', ['user_id' => $workspace->owner_id, 'actor_name' => 'Ada']);
    logged($workspace, 'service.stop', ['user_id' => $grace->id, 'actor_name' => 'Grace']);
    logged($workspace, 'monitor.add', ['user_id' => $bob->id, 'actor_name' => 'Bob']);
    logged($workspace, 'workflow.add', ['actor_name' => 'Zed', 'actor_email' => 'zed@example.com']);
    loggedBySupport($workspace, 'console.unlock');
    logged($workspace, 'machine.offline');

    $people = [];
    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(function (Assert $page) use (&$people) {
            $people = $page->toArray()['props']['people'];
        });

    expect(array_column($people, 'name'))->toBe(['Ada', 'Grace', 'Bob', 'Zed'])
        ->and(array_column($people, 'left'))->toBe([false, false, true, true])
        ->and($people[3]['value'])->toStartWith('gone-')
        ->and(json_encode($people))->not->toContain('zed@example.com')
        ->and(shownActions($workspace, ['person' => (string) $grace->id]))->toBe(['service.stop'])
        ->and(shownActions($workspace, ['person' => (string) $bob->id]))->toBe(['monitor.add'])
        ->and(shownActions($workspace, ['person' => $people[3]['value']]))->toBe(['workflow.add'])
        ->and(shownActions($workspace, ['who' => 'support']))->toBe(['console.unlock'])
        ->and(shownActions($workspace, ['who' => 'system']))->toBe(['machine.offline'])
        ->and(shownActions($workspace, ['who' => 'people']))->toHaveCount(5);

    // Someone who was never here isn't a choice.
    $this->get('https://'.$workspace->host().'/activity?person='.User::factory()->create()->id)->assertSessionHasErrors('person');
});

test('Activity filters by kind of action and by day in the reader’s time zone', function () {
    $workspace = Workspace::factory()->create();
    $this->travelTo('2026-05-10 01:00:00');

    logged($workspace, 'service.deploy', ['created_at' => '2026-05-09 23:30:00']);
    logged($workspace, 'service.restart', ['created_at' => '2026-05-08 12:00:00']);
    logged($workspace, 'backup_destination.add', ['created_at' => '2026-05-08 12:00:00']);
    logged($workspace, 'plan.changed', ['created_at' => '2026-04-01 12:00:00']);

    expect(shownActions($workspace, ['group' => 'deploys']))->toBe(['service.deploy'])
        ->and(shownActions($workspace, ['group' => 'services']))->toBe(['service.restart'])
        ->and(shownActions($workspace, ['group' => 'backups']))->toBe(['backup_destination.add'])
        ->and(shownActions($workspace, ['group' => 'workspace']))->toBe(['plan.changed'])
        // 23:30 UTC on the 9th is already the 10th in Berlin.
        ->and(shownActions($workspace, ['when' => 'today', 'tz' => 'Europe/Berlin']))->toBe(['service.deploy'])
        ->and(shownActions($workspace, ['when' => 'today', 'tz' => 'UTC']))->toBe([])
        ->and(shownActions($workspace, ['when' => '7d']))->toBe(['backup_destination.add', 'service.restart', 'service.deploy'])
        ->and(shownActions($workspace, ['from' => '2026-05-08', 'to' => '2026-05-08']))->toBe(['backup_destination.add', 'service.restart'])
        ->and(shownActions($workspace, ['to' => '2026-04-30']))->toBe(['plan.changed']);

    $this->get('https://'.$workspace->host().'/activity?from=2026-05-08&to=2026-05-01')->assertSessionHasErrors('to');
});

test('every action the app records is in a group', function () {
    $actions = collect(File::allFiles(app_path()))
        ->flatMap(function ($file) {
            // Activity::record($workspace, 'x.y', …), Activity::system(…), and an MCP tool's $context->record('x.y', …).
            preg_match_all("/(?:Activity::(?:record|system)\([^,]+,|->record\()\s*'([a-z_]+\.[a-z_.]+)'/", $file->getContents(), $m);

            return $m[1];
        });
    preg_match_all("/^\s*'([a-z_]+\.[a-z_.]+)' =>/m", File::get(app_path('Support/Activity/ActivityLine.php')), $lines);

    $ungrouped = $actions->merge($lines[1])->unique()->reject(fn (string $a) => ActivityGroups::of($a) !== null)->values()->all();
    expect($actions)->not->toBeEmpty()->and($ungrouped)->toBe([]);
});

test('owners and admins see where an entry came from, never staff’s own IP or browser', function () {
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    logged($workspace, 'service.restart', ['user_id' => $workspace->owner_id, 'source' => 'web', 'ip' => '203.0.113.4', 'user_agent' => CHROME_MAC, 'request_id' => 'abcdef12-3456']);
    loggedBySupport($workspace, 'console.unlock', ['source' => 'web', 'ip' => '198.51.100.9', 'user_agent' => 'StaffBrowser/1.0', 'request_id' => 'feed0000-1111']);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page
            ->where('canExport', true)
            ->where('entries.0.user.name', 'ServerOS Support')
            ->where('entries.0.origin', ['source' => 'web', 'client' => null, 'device' => null, 'ip' => null, 'userAgent' => null, 'requestId' => 'feed0000-1111'])
            ->where('entries.1.origin.device', 'Chrome on macOS')
            ->where('entries.1.origin.ip', '203.0.113.4'));

    $page = $this->actingAs($viewer)->get('https://'.$workspace->host().'/activity');
    $page->assertInertia(fn (Assert $p) => $p->where('canExport', false)->where('entries.0.origin', null)->where('entries.1.origin', null));
    expect($page->getContent())->not->toContain('203.0.113.4')->not->toContain('198.51.100.9');

    $csv = $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity/export')->streamedContent();
    expect($csv)
        ->toContain('203.0.113.4')->toContain(CHROME_MAC)->toContain('abcdef12-3456')
        ->toContain('"ServerOS Support",,yes,web,,console.unlock')
        ->not->toContain('198.51.100.9')->not->toContain('StaffBrowser')->not->toContain('Boss')->not->toContain('boss@serveros.com');
});

test('the export takes the filters on screen, and says so in the log', function () {
    $workspace = Workspace::factory()->create();
    logged($workspace, 'service.restart', ['user_id' => $workspace->owner_id]);
    logged($workspace, 'backup.run', ['user_id' => $workspace->owner_id]);

    $csv = $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity/export?group=backups&tz=Europe/Berlin')->streamedContent();
    expect($csv)->toContain('backup.run')->not->toContain('service.restart');

    $this->get('https://'.$workspace->host().'/activity?group=security')
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.0.action', 'activity.export')
            ->where('entries.0.verb', 'exported the activity log')
            ->where('entries.0.after', 'with filters'));

    // Unfiltered: just "exported the activity log".
    $this->get('https://'.$workspace->host().'/activity/export')->streamedContent();
    expect(Activity::query()->where('action', 'activity.export')->latest('id')->first()->meta)->toBeNull();
});

test('activity is kept as long as the workspace’s plan says', function () {
    expect(Plan::Essential->activityRetentionDays())->toBe(90)
        ->and(Plan::Pro->activityRetentionDays())->toBe(180)
        ->and(Plan::Business->activityRetentionDays())->toBe(365);

    $trial = Workspace::factory()->create();
    $pro = Workspace::factory()->onPlan(Plan::Pro)->create();
    $business = Workspace::factory()->onPlan(Plan::Business)->create();
    foreach ([$trial, $pro, $business] as $workspace) {
        foreach ([30, 100, 200, 400] as $days) {
            logged($workspace, 'machine.offline', ['subject' => "{$days}", 'created_at' => now()->subDays($days)]);
        }
    }

    $this->artisan('logs:prune')->assertSuccessful();

    $kept = fn (Workspace $w) => Activity::query()->where('workspace_id', $w->id)->orderBy('id')->pluck('subject')->all();
    expect($kept($trial))->toBe(['30'])
        ->and($kept($pro))->toBe(['30', '100'])
        ->and($kept($business))->toBe(['30', '100', '200']);

    $this->actingAs($business->owner)->get('https://'.$business->host().'/activity')
        ->assertInertia(fn (Assert $page) => $page->where('keepDays', 365));
    expect(mcpCall($pro, mcpKey($pro), 'get_activity')['data']['keepDays'])->toBe(180);
});

test('get_activity finds ServerOS Support, kinds of action, and people whose account is gone', function () {
    $workspace = Workspace::factory()->create();
    logged($workspace, 'service.deploy', ['user_id' => $workspace->owner_id, 'source' => 'web']);
    logged($workspace, 'service.restart', ['user_id' => $workspace->owner_id]);
    logged($workspace, 'workflow.add', ['actor_name' => 'Zed', 'actor_email' => 'zed@example.com']);
    loggedBySupport($workspace, 'console.unlock');

    $key = mcpKey($workspace);
    $entries = fn (array $args) => mcpCall($workspace, $key, 'get_activity', $args)['data']['entries'];

    $support = $entries(['who' => 'support']);
    expect(array_column($support, 'action'))->toBe(['console.unlock'])
        ->and($support[0]['who'])->toBe(['id' => null, 'name' => 'ServerOS Support', 'support' => true])
        ->and(json_encode($support))->not->toContain('Boss')
        ->and(array_column($entries(['group' => 'deploys']), 'action'))->toBe(['service.deploy'])
        ->and($entries(['group' => 'deploys'])[0]['source'])->toBe('web')
        ->and(array_column($entries(['group' => 'services']), 'action'))->toBe(['service.restart'])
        ->and(array_column($entries(['person' => 'ZED@example.com']), 'action'))->toBe(['workflow.add'])
        ->and(array_column($entries(['person' => 'zed']), 'action'))->toBe(['workflow.add'])
        ->and(mcpCall($workspace, $key, 'get_activity', ['person' => 'Nobody'])['error'])->toBeTrue();
});
