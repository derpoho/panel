<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Logs\LogStore;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/** The settings page's address on the workspace's subdomain. */
function logStorageUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->subdomain().'/settings/log-storage'.$path;
}

/** What the form sends for a Postgres at a public address. */
function logStorageForm(array $overrides = []): array
{
    return ['host' => '203.0.113.10', 'port' => 5432, 'database' => 'logs', 'username' => 'serveros', 'password' => 'hunter2', 'prune' => true, ...$overrides];
}

// The page may be newer than the last local build.
beforeEach(fn () => $this->withoutVite());

afterEach(fn () => LogStore::flush());

test('only Business workspaces can set it up', function () {
    $pro = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($pro->owner)->get(logStorageUrl($pro))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/settings/LogStorage')->where('planAllows', false)->where('store', null));
    $this->actingAs($pro->owner)->put(logStorageUrl($pro), logStorageForm())->assertSessionHasErrors('host');
    $this->actingAs($pro->owner)->post(logStorageUrl($pro, '/test'), logStorageForm())->assertSessionHasErrors('host');

    expect(WorkspaceLogStore::query()->count())->toBe(0);
});

test('saving keeps the connection, off, and the password never reaches the page', function () {
    config()->set('serveros.egress_ip', '198.51.100.7');
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();

    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['prune' => false]))->assertSessionHasNoErrors();

    $store = WorkspaceLogStore::query()->where('workspace_id', $workspace->id)->sole();
    expect($store->enabled)->toBeFalse()
        ->and($store->driver)->toBe('pgsql')
        ->and($store->password)->toBe('hunter2')
        ->and($store->prune)->toBeFalse();

    // A blank password keeps the saved one.
    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['password' => '', 'database' => 'other']))->assertSessionHasNoErrors();
    expect($store->fresh()->password)->toBe('hunter2')->and($store->fresh()->database)->toBe('other');

    $page = $this->actingAs($workspace->owner)->get(logStorageUrl($workspace));
    $page->assertInertia(fn (Assert $p) => $p
        ->where('planAllows', true)
        ->where('egressIp', '198.51.100.7')
        ->where('store.hasPassword', true)
        ->where('store.database', 'other')
        ->missing('store.password'));
    expect($page->getContent())->not->toContain('hunter2');
});

test('the form refuses bad details and private hosts', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();

    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['port' => 70000]))->assertSessionHasErrors('port');
    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['password' => '']))->assertSessionHasErrors('password');
    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['host' => 'db example com']))->assertSessionHasErrors('host');
    foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', 'localhost'] as $private) {
        $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['host' => $private]))->assertSessionHasErrors('host');
    }

    expect(WorkspaceLogStore::query()->count())->toBe(0);
});

test('testing the saved connection says whether it works, without turning it on', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $store = ownLogStore($workspace, ['enabled' => false]);

    $this->actingAs($workspace->owner)->post(logStorageUrl($workspace, '/test'))
        ->assertSessionHasNoErrors();
    expect($store->fresh()->enabled)->toBeFalse()->and($store->fresh()->last_ok_at)->not->toBeNull();

    $store->forceFill(['database' => sys_get_temp_dir().'/no-such-dir-'.uniqid().'/logs.sqlite'])->save();
    $this->actingAs($workspace->owner)->post(logStorageUrl($workspace, '/test'))->assertSessionHasErrors('connection');
    expect($store->fresh()->enabled)->toBeFalse()->and($store->fresh()->status)->toBe('unreachable');
});

test('turning it on creates the tables and sends lines there', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $path = tempnam(sys_get_temp_dir(), 'serveros-logstore-');
    $store = WorkspaceLogStore::query()->forceCreate([
        'workspace_id' => $workspace->id, 'driver' => 'sqlite', 'host' => 'db.example.com', 'port' => 5432,
        'database' => $path, 'username' => 'serveros', 'password' => 'secret', 'enabled' => false,
    ]);
    expect(LogStore::isExternal($workspace))->toBeFalse();

    $this->actingAs($workspace->owner)->post(logStorageUrl($workspace, '/enable'))->assertSessionHasNoErrors();

    expect($store->fresh()->enabled)->toBeTrue()
        ->and(LogStore::connection($workspace))->toBe('logs_ws_'.$workspace->id)
        ->and(Schema::connection(LogStore::connection($workspace))->hasTable('log_entries'))->toBeTrue()
        ->and(Schema::connection(LogStore::connection($workspace))->hasTable('log_counts'))->toBeTrue()
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'workspace.log_storage_enable')->exists())->toBeTrue();
});

test('a store that fails its test is not turned on', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $store = ownLogStore($workspace, ['enabled' => false]);
    $store->forceFill(['database' => sys_get_temp_dir().'/no-such-dir-'.uniqid().'/logs.sqlite'])->save();

    $this->actingAs($workspace->owner)->post(logStorageUrl($workspace, '/enable'))->assertSessionHasErrors('connection');

    expect($store->fresh()->enabled)->toBeFalse()
        ->and(LogStore::isExternal($workspace))->toBeFalse();
});

test('turning it off sends new lines back to ours, and only then can it be removed', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $store = ownLogStore($workspace);
    expect(LogStore::isExternal($workspace))->toBeTrue();

    $this->actingAs($workspace->owner)->delete(logStorageUrl($workspace))->assertSessionHasErrors('connection');
    expect($store->fresh())->not->toBeNull();

    $this->actingAs($workspace->owner)->post(logStorageUrl($workspace, '/disable'))->assertSessionHasNoErrors();
    expect($store->fresh()->enabled)->toBeFalse()
        ->and(LogStore::isExternal($workspace))->toBeFalse();

    $this->actingAs($workspace->owner)->delete(logStorageUrl($workspace))->assertSessionHasNoErrors();
    expect($store->fresh())->toBeNull()
        ->and(Activity::query()->where('workspace_id', $workspace->id)->pluck('action')->all())
        ->toContain('workspace.log_storage_disable', 'workspace.log_storage_remove');
});

test('saving and changing it is recorded', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();

    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm());
    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['password' => '', 'prune' => false]));
    // The same again changes nothing, and records nothing.
    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['password' => '', 'prune' => false]));

    $entries = Activity::query()->where('workspace_id', $workspace->id)->where('action', 'workspace.log_storage_update')->oldest('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->meta)->toMatchArray(['added' => true])
        ->and($entries[1]->meta)->toBe(['prune' => false]);
});

test('changing where an enabled store points tests it first', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $store = ownLogStore($workspace);

    // The sqlite test store can't reach a Postgres at that address.
    $this->actingAs($workspace->owner)->put(logStorageUrl($workspace), logStorageForm(['host' => '203.0.113.99']))
        ->assertSessionHasErrors('connection');

    expect($store->fresh()->host)->toBe('db.example.com');
});

test('members who can’t change settings can’t manage it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $store = ownLogStore($workspace);
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)->get(logStorageUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->actingAs($member)->put(logStorageUrl($workspace), logStorageForm())->assertForbidden();
    $this->actingAs($member)->post(logStorageUrl($workspace, '/test'))->assertForbidden();
    $this->actingAs($member)->post(logStorageUrl($workspace, '/disable'))->assertForbidden();
    $this->actingAs($member)->delete(logStorageUrl($workspace))->assertForbidden();

    expect($store->fresh()->enabled)->toBeTrue();
});
