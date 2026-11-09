<?php

use App\Http\Controllers\ActivityController;
use App\Http\Middleware\SetLocale;
use App\Models\AccountEvent;
use App\Models\Activity;
use App\Models\AdminAction;
use App\Models\DaemonJob;
use App\Models\ImpersonationSession;
use App\Models\ImpersonationView;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationSent;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Sam's workspace with an online machine running one managed container,
 * a fake gateway, and an admin called Boss.
 *
 * @return array{0: User, 1: Workspace, 2: Machine, 3: string}
 */
function viewAsSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $boss = User::factory()->admin()->create(['name' => 'Boss', 'email' => 'boss@serveros.com']);
    $workspace = Workspace::factory()->create();
    $workspace->owner->forceFill(['name' => 'Sam'])->save();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:abc123def456', 'name' => 'umami', 'kind' => 'web', 'manager' => 'compose',
                'status' => 'running', 'ports' => [3000], 'confidence' => 90, 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$boss, $workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/services/docker:abc123def456/actions'];
}

test('a View as is kept from start to the way back', function () {
    [$boss, $workspace] = viewAsSetup();
    $sam = $workspace->owner;

    $this->actingAs($boss)->post(viewAsUrl($sam), ['reason' => 'Their deploy fails'])->assertRedirect(route('dashboard'));
    $session = ImpersonationSession::query()->sole();
    expect($session->only(['admin_id', 'user_id', 'reason', 'admin_name', 'user_email']))->toBe([
        'admin_id' => $boss->id, 'user_id' => $sam->id, 'reason' => 'Their deploy fails', 'admin_name' => 'Boss', 'user_email' => $sam->email,
    ])->and($session->ended_at)->toBeNull();

    $this->travel(5)->minutes();
    $this->post('/admin/return');
    $this->assertAuthenticatedAs($boss);

    $session->refresh();
    expect($session->ended_how)->toBe('returned')
        ->and($session->ended_at)->not->toBeNull()
        ->and(AdminAction::query()->orderBy('id')->pluck('action')->all())->toBe(['user.impersonated', 'user.impersonation_ended'])
        ->and(AdminAction::query()->where('action', 'user.impersonation_ended')->sole()->meta)->toBe(['as' => 'Sam', 'how' => 'returned', 'minutes' => 5])
        ->and(AdminAction::query()->whereNull('impersonation_session_id')->count())->toBe(0);

    // Staff coming and going is ours to know: the workspace's log has none of it.
    expect(Activity::query()->count())->toBe(0);
});

test('a restart while viewing as someone reads as ServerOS Support to them, and names the admin to us', function () {
    [$boss, $workspace, $machine, $actions] = viewAsSetup();
    $sam = $workspace->owner;

    $this->actingAs($boss)->post(viewAsUrl($sam), ['reason' => 'Their deploy fails']);
    $this->postJson($actions, ['action' => 'restart'])->assertAccepted();
    $session = ImpersonationSession::query()->sole();

    $entry = Activity::query()->sole();
    expect($entry->user_id)->toBeNull()
        ->and($entry->impersonation_session_id)->toBe($session->id)
        ->and($entry->actor_name)->toBeNull()
        ->and($entry->source)->toBe('web')
        ->and($entry->request_id)->not->toBeNull();

    // The job, and what the machine's own audit log is told.
    $job = DaemonJob::query()->sole();
    expect($job->impersonation_session_id)->toBe($session->id)
        ->and($job->startedByName())->toBe('ServerOS Support')
        ->and($job->command['actor']['name'])->toBe('ServerOS Support');

    // Our copy, which outlives the workspace's 90 days.
    $copy = AdminAction::query()->where('action', 'viewas.write')->sole();
    expect($copy->admin_id)->toBe($boss->id)
        ->and($copy->impersonation_session_id)->toBe($session->id)
        ->and($copy->target_type)->toBe('workspace')
        ->and($copy->meta)->toMatchArray(['action' => 'service.restart', 'as' => 'Sam', 'what' => 'restarted umami', 'machine' => 'web-1']);

    $this->post(rtrim(config('app.url'), '/').'/admin/return');

    // What Sam sees: generic, with no trace of which admin.
    $page = $this->actingAs($sam)->get('https://'.$workspace->host().'/activity');
    $page->assertInertia(fn (Assert $p) => $p
        ->where('entries.0.user', ['name' => 'ServerOS Support', 'avatar' => null, 'support' => true])
        ->where('entries.0.verb', 'restarted'));
    expect($page->getContent())->not->toContain('Boss')->not->toContain('boss@serveros.com');

    $this->get('https://'.$workspace->host().'/activity?who=people')->assertInertia(fn (Assert $p) => $p->has('entries', 1));
    $this->get('https://'.$workspace->host().'/activity?who=system')->assertInertia(fn (Assert $p) => $p->has('entries', 0));

    $csv = $this->get('https://'.$workspace->host().'/activity/export')->streamedContent();
    expect($csv)->toContain('ServerOS Support')->not->toContain('Boss')->not->toContain('boss@serveros.com');
});

test('what Sam does themselves is still theirs', function () {
    [, $workspace, , $actions] = viewAsSetup();

    $this->actingAs($workspace->owner)->postJson($actions, ['action' => 'restart'])->assertAccepted();

    $entry = Activity::query()->sole();
    expect($entry->user_id)->toBe($workspace->owner->id)
        ->and($entry->impersonation_session_id)->toBeNull()
        ->and($entry->actor_name)->toBe('Sam')
        ->and(DaemonJob::query()->sole()->command['actor']['name'])->toBe($workspace->owner->email)
        ->and(AdminAction::query()->count())->toBe(0);
});

test('signing out while viewing as someone ends the session', function () {
    [$boss, $workspace] = viewAsSetup();

    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Checking billing']);
    $this->post('/logout');

    expect(ImpersonationSession::query()->sole()->ended_how)->toBe('signed_out')
        ->and(AdminAction::query()->where('action', 'user.impersonation_ended')->count())->toBe(1);
});

test('a View as whose admin is deleted ends as revoked, and the end still names them', function () {
    [$boss, $workspace] = viewAsSetup();
    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Helping']);
    $boss->delete();

    $this->get('https://'.$workspace->host())->assertRedirect(rtrim((string) config('app.url'), '/').'/login');

    $session = ImpersonationSession::query()->sole();
    $end = AdminAction::query()->where('action', 'user.impersonation_ended')->sole();
    expect($session->ended_how)->toBe('admin_revoked')
        ->and($end->admin_id)->toBeNull()
        ->and($end->adminName())->toBe('Boss')
        ->and($end->impersonation_session_id)->toBe($session->id);
});

test('starting a View as leaves the person’s language alone', function () {
    [$boss, $workspace] = viewAsSetup();
    $workspace->owner->forceFill(['locale' => 'en'])->save();

    $this->actingAs($boss)->withCookie(SetLocale::COOKIE, 'de')
        ->post(viewAsUrl($workspace->owner), ['reason' => 'Helping']);

    expect($workspace->owner->fresh()->locale)->toBe('en');
});

test('a secret in a page’s address is not kept with the pages opened', function () {
    [$boss, $workspace] = viewAsSetup();
    WorkspaceInvitation::factory()->for(Workspace::factory()->create())->withToken('plain-invitation-token')->create();
    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Helping']);

    $this->get('/invitations/plain-invitation-token')->assertOk();

    expect(ImpersonationView::query()->where('route', 'invitations.show')->sole()->parameters)->toBe(['token' => '…']);
});

test('View as sessions are never edited or deleted from the app', function () {
    [$boss, $workspace] = viewAsSetup();
    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Helping']);
    $session = ImpersonationSession::query()->sole();

    expect(fn () => $session->update(['reason' => 'Changed my mind']))->toThrow(LogicException::class)
        ->and(fn () => $session->delete())->toThrow(LogicException::class);
});

test('a View as that goes quiet is closed as of when it was last used', function () {
    [$boss, $workspace] = viewAsSetup();

    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Checking billing']);
    $session = ImpersonationSession::query()->sole();
    $lastSeen = $session->last_seen_at;

    $this->travel((int) config('session.lifetime') + 10)->minutes();
    $this->artisan('view-as:close-stale')->assertSuccessful();

    $session->refresh();
    expect($session->ended_how)->toBe('expired')
        ->and($session->ended_at->equalTo($lastSeen))->toBeTrue()
        ->and(AdminAction::query()->where('action', 'user.impersonation_ended')->sole()->meta['how'])->toBe('expired');
});

test('pages opened while viewing as someone are noted by route, once a minute each', function () {
    [$boss, $workspace, $machine] = viewAsSetup();

    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Checking billing']);
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id;
    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->get('https://'.$workspace->host().'/activity')->assertOk();
    // A minute on, the same page counts again.
    $this->travel(61)->seconds();
    $this->get($url)->assertOk();

    $views = ImpersonationView::query()->orderBy('id')->get();
    expect($views->pluck('route')->all())->toBe(['workspace.machines.show', 'workspace.activity.index', 'workspace.machines.show'])
        ->and($views[0]->parameters)->toMatchArray(['machine' => (string) $machine->id]);

    // Nothing is noted for an admin browsing as themselves.
    $this->post(rtrim(config('app.url'), '/').'/admin/return');
    $this->get(route('profile.edit'))->assertOk();
    expect(ImpersonationView::query()->count())->toBe(3);
});

test('someone who left is still named in the activity', function () {
    [, $workspace, , $actions] = viewAsSetup();
    $ada = User::factory()->create(['name' => 'Ada']);
    $workspace->members()->attach($ada, ['role' => 'admin']);

    $this->actingAs($ada)->postJson($actions, ['action' => 'restart'])->assertAccepted();
    $ada->delete();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity')
        ->assertInertia(fn (Assert $p) => $p->where('entries.0.user.name', 'Ada')->where('entries.0.leads', false));
});

test('an entry nobody did keeps the IP and browser of whoever set it off out of the workspace', function () {
    [$boss, $workspace] = viewAsSetup();
    $workspace->forceFill(['plan' => null])->save();
    // Something staff set off that the workspace reads as nobody's, like
    // extending its trial from the staff admin.
    Route::post('test/extend-trial/{id}', function (int $id) {
        Activity::system(Workspace::query()->findOrFail($id), 'plan.trial_extended', null, null, ['days' => 7, 'until' => now()->addDays(7)->format('j M Y')]);

        return response()->noContent();
    })->middleware(['web', 'auth', 'can:viewAdmin']);

    $this->actingAs($boss)->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/155.0')
        ->post(rtrim(config('app.url'), '/')."/test/extend-trial/{$workspace->id}")->assertNoContent();

    $entry = Activity::query()->where('action', 'plan.trial_extended')->sole();
    expect($entry->ip)->toBeNull()->and($entry->user_agent)->toBeNull()->and($entry->request_id)->toBeNull();

    // Entries that do have them stored don't show them either.
    $entry->getConnection()->table('activities')->where('id', $entry->id)->update(['ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (Macintosh) Chrome/155.0']);
    $origin = ActivityController::origin($entry->fresh());
    expect($origin['ip'])->toBeNull()->and($origin['device'])->toBeNull()->and($origin['userAgent'])->toBeNull();

    $csv = $this->flushHeaders()->actingAs($workspace->owner)->get('https://'.$workspace->host().'/activity/export')->streamedContent();
    expect($csv)->not->toContain('203.0.113.9')->not->toContain('Chrome/155.0');
});

test('an admin who loses their access while viewing as someone is signed out, and nothing more is theirs', function () {
    [$boss, $workspace, , $actions] = viewAsSetup();

    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Their deploy fails']);
    $boss->forceFill(['is_admin' => false])->save();

    $this->postJson($actions, ['action' => 'restart'])->assertForbidden();

    $this->assertGuest();
    expect(ImpersonationSession::query()->sole()->ended_how)->toBe('admin_revoked')
        ->and(Activity::query()->count())->toBe(0)
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('the end of a View as is noted even when the account was deleted meanwhile', function () {
    [$boss, $workspace] = viewAsSetup();
    $sam = $workspace->owner;

    $this->actingAs($boss)->post(viewAsUrl($sam), ['reason' => 'Closing their account']);
    $session = ImpersonationSession::query()->sole();
    $sam->delete();
    $session->refresh()->finish('returned');

    $ended = AdminAction::query()->where('action', 'user.impersonation_ended')->sole();
    expect($ended->target_id)->toBeNull()
        ->and($ended->meta)->toMatchArray(['as' => 'Sam', 'how' => 'returned']);
});

test('an invitation sent while viewing as someone comes from ServerOS Support', function () {
    [$boss, $workspace] = viewAsSetup();
    Notification::fake();

    $this->actingAs($boss)->post(viewAsUrl($workspace->owner), ['reason' => 'Setting up their team']);
    $this->post('https://'.$workspace->host().'/settings/invitations', ['email' => 'kim@example.com', 'role' => 'admin'])->assertSessionHasNoErrors();

    $invitation = WorkspaceInvitation::query()->sole();
    expect($invitation->impersonation_session_id)->not->toBeNull()
        ->and($invitation->inviterName())->toBe('ServerOS Support');
    Notification::assertSentOnDemand(WorkspaceInvitationSent::class, function (WorkspaceInvitationSent $n) {
        $mail = $n->toMail(new AnonymousNotifiable);

        return str_contains(implode(' ', $mail->introLines), 'ServerOS Support invited you') && ! str_contains(implode(' ', $mail->introLines), 'Sam');
    });
});

test('staff history is kept two years, and a session something is still credited to stays', function () {
    [$boss, $workspace, $machine] = viewAsSetup();
    $sam = $workspace->owner;
    $session = fn () => ImpersonationSession::query()->create([
        'admin_id' => $boss->id, 'user_id' => $sam->id, 'admin_name' => 'Boss', 'admin_email' => 'boss@serveros.com',
        'user_name' => 'Sam', 'user_email' => $sam->email, 'reason' => 'Old', 'started_at' => now()->subYears(3), 'last_seen_at' => now()->subYears(3), 'ended_at' => now()->subYears(3),
    ]);
    $old = $session();
    $credited = $session();
    ImpersonationView::query()->create(['impersonation_session_id' => $old->id, 'route' => 'workspace.home', 'created_at' => now()->subYears(3)]);
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $sam->id, 'impersonation_session_id' => $credited->id, 'kind' => 'deploy', 'command' => [], 'state' => 'succeeded', 'log' => []]);
    AdminAction::record($boss, 'system.test_mail', null, ['to' => 'boss@serveros.com']);
    AdminAction::query()->getConnection()->table('admin_actions')->update(['created_at' => now()->subYears(3)]);
    AdminAction::record($boss, 'system.test_mail', null, ['to' => 'boss@serveros.com']);
    // One session per other table that can still point at one.
    $byActivity = $session();
    Activity::query()->create(['workspace_id' => $workspace->id, 'action' => 'service.restart', 'subject' => 'umami', 'impersonation_session_id' => $byActivity->id]);
    $byEvent = $session();
    AccountEvent::query()->create(['user_id' => $sam->id, 'email' => $sam->email, 'event' => 'avatar.changed', 'impersonation_session_id' => $byEvent->id]);
    $byInvitation = $session();
    WorkspaceInvitation::factory()->for($workspace)->create(['impersonation_session_id' => $byInvitation->id]);
    $byAction = $session();
    AdminAction::recordForSession($byAction, 'viewas.write', $workspace, ['action' => 'workspace.rename', 'as' => 'Sam', 'what' => 'renamed the workspace to Acme']);

    $this->artisan('logs:prune')->assertSuccessful();

    expect(AdminAction::query()->pluck('action')->all())->toBe(['system.test_mail', 'viewas.write'])
        ->and(ImpersonationSession::query()->pluck('id')->all())->toEqualCanonicalizing([$credited->id, $byActivity->id, $byEvent->id, $byInvitation->id, $byAction->id])
        ->and(ImpersonationView::query()->count())->toBe(0)
        ->and(DaemonJob::query()->sole()->startedByName())->toBe('ServerOS Support');
});

test('the admin log reads in English whatever language the admin browses in', function () {
    [$boss, $workspace] = viewAsSetup();
    $sam = $workspace->owner;
    $was = $workspace->name;

    $this->actingAs($boss)->withCookie(SetLocale::COOKIE, 'de')->post(viewAsUrl($sam), ['reason' => 'Helping']);
    $this->withCookie(SetLocale::COOKIE, 'de')->patch('https://'.$workspace->host().'/settings/general', ['name' => 'Acme'])->assertRedirect();
    Storage::fake('public');
    $this->withCookie(SetLocale::COOKIE, 'de')->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png')])->assertRedirect();

    $copies = AdminAction::query()->where('action', 'viewas.write')->orderBy('id')->get();
    expect($copies)->toHaveCount(2)
        ->and($copies[0]->meta['what'])->toBe("renamed the workspace to Acme (was {$was})")
        ->and($copies[1]->meta['what'])->toBe('changed their profile picture');
});
