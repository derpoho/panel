<?php

use App\Enums\Plan;
use App\Mail\StatusPageUpdate;
use App\Mail\StatusSubscribeConfirm;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusSubscriber;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Mail\Unsubscribes;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\ProbeResult;
use App\Support\StatusPages\StatusIncidents;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A Business workspace with a machine whose live public page is
 * /status/web-one, a live public workspace page at /status/acme, and a
 * website monitor on the machine.
 *
 * @return array{0: Workspace, 1: Machine, 2: StatusPage, 3: WorkspaceStatusPage, 4: Monitor}
 */
function subscriptionsSetup(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'Web One']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => []]])->save();
    $page = new StatusPage(['slug' => 'web-one', 'title' => 'Shop status', 'enabled' => true, 'public' => true, 'show_uptime' => true, 'include_new' => true, 'services' => []]);
    $page->machine_id = $machine->id;
    $page->save();
    $fleet = new WorkspaceStatusPage(['slug' => 'acme', 'enabled' => true, 'public' => true, 'show_uptime' => true]);
    $fleet->workspace_id = $workspace->id;
    $fleet->save();
    $monitor = $workspace->monitors()->create(['machine_id' => $machine->id, 'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    return [$workspace, $machine, $page, $fleet, $monitor];
}

/** @param  StatusPage|WorkspaceStatusPage  $page */
function confirmedSubscriber($page, string $email): StatusSubscriber
{
    return $page->subscribers()->create(['email' => $email, 'token' => Str::random(48), 'confirmed_at' => now()]);
}

test('a visitor subscribes, confirms from the email, and the page offers it', function () {
    Mail::fake();
    [, , $page] = subscriptionsSetup();

    $this->get('/status/web-one')->assertInertia(fn (Assert $p) => $p
        ->where('subscribeUrl', route('status.show', 'web-one').'/subscribe')
        ->where('feedUrl', route('status.show', 'web-one').'/feed.atom'));

    $this->postJson('/status/web-one/subscribe', ['email' => 'Ada@Example.com'])->assertAccepted();
    $subscriber = $page->subscribers()->sole();
    expect($subscriber->email)->toBe('ada@example.com')->and($subscriber->confirmed_at)->toBeNull();
    Mail::assertQueued(StatusSubscribeConfirm::class, fn ($mail) => $mail->hasTo('ada@example.com') && $mail->pageTitle === 'Shop status');

    // Asking again within the hour doesn't send another.
    $this->postJson('/status/web-one/subscribe', ['email' => 'ada@example.com'])->assertAccepted();
    Mail::assertQueuedCount(1);

    $this->get("/status/web-one/subscribe/{$subscriber->token}")->assertOk()->assertSee('You’re subscribed');
    expect($subscriber->fresh()->confirmed_at)->not->toBeNull();
    $this->get('/status/web-one/subscribe/not-a-token')->assertNotFound();
});

test('private pages and pages that are off can’t be subscribed to', function () {
    [, , $page] = subscriptionsSetup();
    $page->update(['public' => false]);
    $this->postJson('/status/web-one/subscribe', ['email' => 'ada@example.com'])->assertNotFound();
    $this->get('/status/acme/feed.atom')->assertOk();
    $page->update(['public' => true, 'enabled' => false]);
    $this->postJson('/status/web-one/subscribe', ['email' => 'ada@example.com'])->assertNotFound();
});

test('a full page turns new subscribers away', function () {
    Mail::fake();
    [, , $page] = subscriptionsSetup();
    $page->machine->workspace->forceFill(['plan' => Plan::Pro])->save();
    StatusSubscriber::query()->insert(array_map(fn (int $i) => [
        'subscribable_type' => StatusPage::class, 'subscribable_id' => $page->id,
        'email' => "p{$i}@example.com", 'token' => Str::random(48), 'created_at' => now(), 'updated_at' => now(),
    ], range(1, Plan::Pro->statusSubscriberLimit())));

    $this->postJson('/status/web-one/subscribe', ['email' => 'late@example.com'])->assertConflict();
});

test('incidents email confirmed subscribers once each, never the unconfirmed or unsubscribed', function () {
    Mail::fake();
    [$workspace, $machine, $page, $fleet] = subscriptionsSetup();
    confirmedSubscriber($page, 'both@example.com');
    confirmedSubscriber($fleet, 'both@example.com');
    confirmedSubscriber($fleet, 'fleet@example.com');
    confirmedSubscriber($page, 'gone@example.com');
    Unsubscribes::add('gone@example.com');
    $page->subscribers()->create(['email' => 'pending@example.com', 'token' => Str::random(48)]);

    $incident = StatusIncidents::open($workspace, $machine, $workspace->owner, 'Checkout is failing', 'major', 'investigating', 'Looking into it.');
    Mail::assertQueued(StatusPageUpdate::class, 2);
    Mail::assertQueued(StatusPageUpdate::class, fn ($mail) => $mail->hasTo('both@example.com') && $mail->subjectLine === 'Checkout is failing' && $mail->state === 'Investigating');
    Mail::assertQueued(StatusPageUpdate::class, fn ($mail) => $mail->hasTo('fleet@example.com'));

    StatusIncidents::update($incident, $workspace->owner, 'resolved', 'Fixed.');
    Mail::assertQueued(StatusPageUpdate::class, fn ($mail) => $mail->subjectLine === 'Resolved: Checkout is failing');

    // One about nothing in particular only reaches the workspace page's subscribers.
    Mail::fake();
    StatusIncidents::open($workspace, null, $workspace->owner, 'Slow emails', 'degraded', 'monitoring', 'Late.');
    Mail::assertQueued(StatusPageUpdate::class, 2);
    Mail::assertQueued(StatusPageUpdate::class, fn ($mail) => $mail->hasTo('both@example.com') && $mail->pageTitle === $workspace->name);
});

test('a monitor the page shows going down and coming back emails subscribers, except in maintenance', function () {
    Mail::fake();
    [, $machine, $page, , $monitor] = subscriptionsSetup();
    confirmedSubscriber($page, 'ada@example.com');
    $runner = app(MonitorRunner::class);

    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        $runner->apply($monitor->fresh(), ProbeResult::fail('Timed out'));
    }
    Mail::assertQueued(StatusPageUpdate::class, fn ($mail) => $mail->hasTo('ada@example.com') && $mail->subjectLine === 'Shop is down');

    $runner->apply($monitor->fresh(), new ProbeResult(true, 90, 200));
    Mail::assertQueued(StatusPageUpdate::class, fn ($mail) => $mail->subjectLine === 'Shop is back up');

    Mail::fake();
    $machine->forceFill(['maintenance_until' => now()->addHour()])->save();
    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        $runner->apply($monitor->fresh(), ProbeResult::fail('Timed out'));
    }
    Mail::assertNothingQueued();
});

test('unsubscribing asks first, and a one-click POST does it without a CSRF token', function () {
    [, , $page] = subscriptionsSetup();
    $subscriber = confirmedSubscriber($page, 'ada@example.com');

    $this->get("/status/web-one/unsubscribe/{$subscriber->token}")->assertOk()->assertSee('Stop emails from Shop status?', false);
    expect($subscriber->fresh())->not->toBeNull();

    $this->post("/status/web-one/unsubscribe/{$subscriber->token}")->assertOk()->assertSee('You’re unsubscribed', false);
    expect(StatusSubscriber::query()->count())->toBe(0);
    // An old link still says it's done.
    $this->get("/status/web-one/unsubscribe/{$subscriber->token}")->assertOk()->assertSee('You’re unsubscribed', false);
});

test('the feed lists incident updates as Atom', function () {
    Mail::fake();
    [$workspace, $machine] = subscriptionsSetup();
    $incident = StatusIncidents::open($workspace, $machine, null, 'Checkout is failing', 'major', 'investigating', 'Looking into it.');
    StatusIncidents::update($incident, null, 'resolved', 'Fixed & checked.');

    $this->get('/status/web-one/feed.atom')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/atom+xml; charset=utf-8')
        ->assertSee('<title>Resolved: Checkout is failing</title>', false)
        ->assertSee('Fixed &amp; checked.', false)
        ->assertSee('<title>Investigating: Checkout is failing</title>', false);
});

test('owners see how many people subscribed', function () {
    [$workspace, $machine, $page, $fleet] = subscriptionsSetup();
    confirmedSubscriber($page, 'ada@example.com');
    $page->subscribers()->create(['email' => 'pending@example.com', 'token' => Str::random(48)]);
    confirmedSubscriber($fleet, 'grace@example.com');
    $base = 'https://'.$workspace->host();

    $this->actingAs($workspace->owner)->get("{$base}/machines/{$machine->id}/settings/status")
        ->assertInertia(fn (Assert $p) => $p->where('statusPage.subscribers', 1));
    $this->actingAs($workspace->owner)->get("{$base}/settings/status-page")
        ->assertInertia(fn (Assert $p) => $p->where('page.subscribers', 1));
});
