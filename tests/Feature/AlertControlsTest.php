<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Notifications\WeeklySummaryMail;
use App\Support\Health\ServiceWatcher;
use App\Support\Mail\Unsubscribes;
use App\Support\Monitors\MonitorAlerts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function alertWorkspace(array $attributes = []): Workspace
{
    return Workspace::factory()->for(User::factory()->create(['email' => 'sam@acme.dev']), 'owner')->create($attributes);
}

function sendAlert(Workspace $workspace, string $event): void
{
    app(MonitorAlerts::class)->notify($workspace, $event, 'Something happened', ['A line.'], 'https://'.$workspace->host(), true, 'Open it');
}

test('a kind of alert switched off is sent neither by email nor webhook, the rest still are', function () {
    Notification::fake();
    Http::fake();
    $workspace = alertWorkspace(['alert_webhook_url' => 'https://hooks.example.com/x', 'alert_muted' => ['services']]);

    sendAlert($workspace, 'service.down');
    Notification::assertNothingSent();
    Http::assertNothingSent();

    sendAlert($workspace, 'backup.failed');
    // A workflow's own "Send an alert" step belongs to no kind: always sent.
    sendAlert($workspace, 'workflow.alert');
    Notification::assertSentToTimes($workspace->owner, MonitorAlert::class, 2);
});

test('the alerts dialog saves which kinds are off, and leaving them out keeps them', function () {
    $workspace = alertWorkspace();
    $url = 'https://'.$workspace->host().'/monitor-alerts';

    $this->actingAs($workspace->owner)->put($url, ['email' => true, 'webhook' => '', 'muted' => ['services', 'nope']])->assertSessionHasErrors('muted.1');

    $this->actingAs($workspace->owner)->put($url, ['email' => true, 'webhook' => '', 'muted' => ['services', 'backups']])->assertRedirect();
    expect($workspace->fresh()->alert_muted)->toBe(['services', 'backups']);

    $this->actingAs($workspace->owner)->put($url, ['email' => false, 'webhook' => ''])->assertRedirect();
    expect($workspace->fresh()->alert_muted)->toBe(['services', 'backups']);
});

test('a Swarm task that stops because Swarm replaced it is not an outage', function () {
    Notification::fake();
    $workspace = alertWorkspace();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'vps1001']);
    $task = fn (string $key, string $name, string $status) => ['key' => $key, 'name' => $name, 'kind' => 'app', 'manager' => 'docker', 'status' => $status, 'ports' => []];

    $state = ['running' => ['docker:aaa111aaa111', 'docker:ccc333ccc333']];
    $machine->forceFill(['inventory' => ['services' => [
        // Replaced: the same slot is running as a new task.
        $task('docker:aaa111aaa111', 'shop-frontend.1.93lvikzipqkdzycvjh2kaqjqq', 'stopped'),
        $task('docker:bbb222bbb222', 'shop-frontend.1.0252o4vsa1wfm0tis9eh5grxm', 'running'),
        // Not replaced: a real outage.
        $task('docker:ccc333ccc333', 'shop-api.1.1einow5eoyo7su3x5avbj0nl1', 'stopped'),
    ]]])->save();

    app(ServiceWatcher::class)->check($machine, $state);

    expect(array_keys($state['down']))->toBe(['docker:ccc333ccc333']);
    Notification::assertSentToTimes($workspace->owner, MonitorAlert::class, 1);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $mail) => str_starts_with($mail->subject, 'shop-api.1.'));
});

test('one already listed as stopped is cleared without a "running again" email once Swarm replaces it', function () {
    Notification::fake();
    $workspace = alertWorkspace();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['inventory' => ['services' => [
        ['key' => 'docker:aaa111aaa111', 'name' => 'web.2.93lvikzipqkdzycvjh2kaqjqq', 'status' => 'stopped'],
        ['key' => 'docker:bbb222bbb222', 'name' => 'web.2.0252o4vsa1wfm0tis9eh5grxm', 'status' => 'running'],
    ]]])->save();
    $state = ['running' => ['docker:bbb222bbb222'], 'down' => ['docker:aaa111aaa111' => now()->subHour()->getTimestamp()]];

    app(ServiceWatcher::class)->check($machine, $state);

    expect($state['down'])->toBe([]);
    Notification::assertNothingSent();
});

test('alert emails carry a one-click unsubscribe for that workspace, which turns off only that person’s alert emails', function () {
    $workspace = alertWorkspace();
    $mail = (new MonitorAlert('Down', ['A line.'], 'https://x.test', true, 'Open', $workspace->id))->toMail($workspace->owner);
    $url = Unsubscribes::url('sam@acme.dev', 'alerts', $workspace->id);

    expect($mail->salutation)->toContain($url)->toContain('Stop alert emails from this workspace');

    $this->get($url)->assertOk()->assertSee('Stop alert emails from '.$workspace->name.'?');
    $this->post($url)->assertOk()->assertSee('Alert emails are off');

    expect($workspace->members()->withPivot('alert_emails')->find($workspace->owner_id)->pivot->alert_emails)->toBe('none');
    // Not on the list for everything else.
    expect(Unsubscribes::has('sam@acme.dev'))->toBeFalse();
});

test('the weekly summary unsubscribes from the summary alone', function () {
    $workspace = alertWorkspace();
    $mail = (new WeeklySummaryMail($workspace->name, ['headline' => 'Quiet week', 'sections' => []], 'https://x.test', $workspace->id))->toMail($workspace->owner);
    $url = Unsubscribes::url('sam@acme.dev', 'summary', $workspace->id);
    expect($mail->salutation)->toContain($url);

    $this->post($url)->assertOk()->assertSee('Weekly summary is off');

    expect((bool) $workspace->members()->withPivot('weekly_summary')->find($workspace->owner_id)->pivot->weekly_summary)->toBeFalse();
});

test('an unsubscribe link for a list must be signed and name a workspace', function () {
    $workspace = alertWorkspace();

    $this->get(URL::route('unsubscribe', ['email' => 'sam@acme.dev', 'list' => 'alerts', 'workspace' => $workspace->id]))->assertForbidden();
    $this->get(URL::signedRoute('unsubscribe', ['email' => 'sam@acme.dev', 'list' => 'alerts']))->assertNotFound();
    $this->get(URL::signedRoute('unsubscribe', ['email' => 'sam@acme.dev', 'list' => 'everything', 'workspace' => $workspace->id]))->assertNotFound();
});
