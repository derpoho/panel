<?php

use App\Enums\Plan;
use App\Jobs\DiagnoseFix;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Fixes\FixActions;
use App\Support\Fixes\FixDiagnoser;
use App\Support\Logs\LogStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.groq.key', 'gsk_test');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    $this->withoutDefer();
});

/**
 * A Pro workspace whose owner has two-factor on, with a machine running a
 * managed container (shop), a managed unit (nginx) and an unmanaged one.
 *
 * @return array{0: Workspace, 1: Machine}
 */
function fixWorkspace(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'disk_percent' => 41,
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:shop', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'exited', 'ports' => [], 'details' => ['managed' => 'true', 'exit_code' => '137']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:cron.service', 'name' => 'cron', 'kind' => 'other', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'details' => []],
        ]],
    ])->save();

    return [$workspace, $machine];
}

function fixUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/fixes'.$path;
}

/** The model's answer, as Groq sends it. */
function groqAnswer(array $answer): void
{
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($answer)]]]]),
    ]);
}

function fixJobDone(Machine $machine, DaemonJob $job, string $state = 'succeeded', array $result = []): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => $state, 'log' => ['ok'], 'result' => $result]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('asking the agent starts an investigation in the background', function () {
    Queue::fake();
    [$workspace, $machine] = fixWorkspace();

    $this->actingAs($workspace->owner)->post(fixUrl($workspace), ['machine' => $machine->id, 'service' => 'docker:shop', 'note' => 'The shop is down'])
        ->assertRedirect();

    $fix = AgentFix::query()->sole();
    expect($fix->only(['state', 'service_key', 'service_name', 'note']))->toBe(['state' => 'investigating', 'service_key' => 'docker:shop', 'service_name' => 'shop', 'note' => 'The shop is down']);
    Queue::assertPushed(DiagnoseFix::class, fn (DiagnoseFix $job) => $job->fixId === $fix->id);
    expect(Activity::query()->where('action', 'fix.start')->exists())->toBeTrue();
});

test('the agent reads the logs and state, and only steps from the allowlist survive', function () {
    [$workspace, $machine] = fixWorkspace();
    LogStore::entries($workspace)->insert([
        'machine_id' => $machine->id, 'service_key' => 'docker:shop', 'service' => 'shop', 'logged_at' => now()->getTimestampMs(), 'level' => 'error', 'stream' => 'stderr', 'message' => 'Out of memory: Killed process 4121 (node)',
    ]);
    groqAnswer([
        'title' => 'shop was killed for using too much memory',
        'cause' => 'The kernel killed node when it ran out of memory.',
        'explanation' => 'The log says `Out of memory: Killed process 4121 (node)`.',
        'confidence' => 'high',
        'steps' => [
            ['type' => 'start', 'service' => 'docker:shop', 'why' => 'Bring it back.'],
            ['type' => 'command', 'service' => 'docker:shop', 'command' => 'node --version', 'why' => 'Check node.'],
            ['type' => 'reboot', 'service' => 'docker:shop', 'why' => 'Not allowed.'],
            ['type' => 'restart', 'service' => 'systemd:cron.service', 'why' => 'Not managed.'],
            ['type' => 'command', 'service' => 'systemd:nginx.service', 'command' => 'rm -rf /', 'why' => 'Not a container.'],
            ['type' => 'restart', 'service' => 'docker:gone', 'why' => 'Not there.'],
        ],
    ]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'service_key' => 'docker:shop', 'service_name' => 'shop', 'state' => 'investigating']);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));
    $fix->refresh();

    expect($fix->state)->toBe('needs_approval')
        ->and($fix->title)->toBe('shop was killed for using too much memory')
        ->and($fix->confidence)->toBe('high')
        ->and(collect($fix->actions)->map(fn ($a) => [$a['type'], $a['service']])->all())->toBe([['start', 'docker:shop'], ['command', 'docker:shop']])
        ->and($fix->evidence['logs'][0])->toContain('Out of memory')
        ->and($fix->evidence['service']['details']['exit_code'])->toBe('137');

    // The model was told the evidence and the allowlist, and asked for JSON.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.groq.com')
        && $r['response_format'] === ['type' => 'json_object']
        && str_contains($r['messages'][1]['content'], 'Out of memory')
        && str_contains($r['messages'][0]['content'], '- command: Run a shell command inside a managed container'));
});

test('no steps means nothing to run', function () {
    [$workspace, $machine] = fixWorkspace();
    groqAnswer(['title' => 'Needs a code change', 'cause' => 'A missing setting.', 'explanation' => 'Set DATABASE_URL.', 'confidence' => 'medium', 'steps' => []]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'investigating']);
    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));
    expect($fix->refresh()->state)->toBe('nothing_to_do');
});

test('a model that doesn’t answer says so', function () {
    [$workspace, $machine] = fixWorkspace();
    Http::fake(['api.groq.com/*' => Http::response('busy', 503)]);
    $broken = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'investigating']);
    (new DiagnoseFix($broken->id))->handle(app(FixDiagnoser::class));
    expect($broken->refresh()->only(['state', 'error']))->toBe(['state' => 'error', 'error' => 'The model didn’t answer (503). Try again in a minute.']);
});

test('approving runs the steps one at a time as the approver, and stops at a failure', function () {
    [$workspace, $machine] = fixWorkspace();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $fix = AgentFix::query()->create([
        'workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'needs_approval', 'title' => 'shop is down',
        'actions' => FixActions::clean([
            ['type' => 'start', 'service' => 'docker:shop', 'why' => 'a'],
            ['type' => 'command', 'service' => 'docker:shop', 'command' => 'php artisan migrate --force', 'why' => 'b'],
            ['type' => 'reload', 'service' => 'systemd:nginx.service', 'why' => 'c'],
        ], $machine),
    ]);

    $this->actingAs($workspace->owner)->post(fixUrl($workspace, "/{$fix->id}/approve"))->assertRedirect();
    $first = DaemonJob::query()->sole();
    expect($fix->refresh()->state)->toBe('running')
        ->and($fix->decided_by)->toBe($workspace->owner_id)
        ->and($first->only(['kind', 'service', 'agent_fix_id', 'user_id']))->toBe(['kind' => 'service_action', 'service' => 'docker:shop', 'agent_fix_id' => $fix->id, 'user_id' => $workspace->owner_id])
        ->and($first->command['job']['action'])->toBe('start');

    fixJobDone($machine, $first);
    // (The machine also rescans after a service action: not the fix's.)
    $second = DaemonJob::query()->where('agent_fix_id', $fix->id)->where('id', '!=', $first->id)->sole();
    expect($second->kind)->toBe('service_exec')->and($second->command['job']['command'])->toBe('php artisan migrate --force');

    // The command ran but exited 1: the fix stops there.
    fixJobDone($machine, $second, 'succeeded', ['exit_code' => 1]);
    $fix->refresh();
    expect($fix->state)->toBe('failed')
        ->and($fix->results[1]['message'])->toBe('The command stopped with exit code 1.')
        ->and(DaemonJob::query()->where('agent_fix_id', $fix->id)->count())->toBe(2);
});

test('when every step works the fix is done', function () {
    [$workspace, $machine] = fixWorkspace();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'needs_approval', 'title' => 'nginx is stale',
        'actions' => FixActions::clean([['type' => 'reload', 'service' => 'systemd:nginx.service', 'why' => 'x']], $machine)]);

    $this->actingAs($workspace->owner)->post(fixUrl($workspace, "/{$fix->id}/approve"))->assertRedirect();
    fixJobDone($machine, DaemonJob::query()->sole());

    expect($fix->refresh()->state)->toBe('fixed')
        ->and(Activity::query()->pluck('action')->all())->toContain('fix.approve', 'fix.done');
});

test('approving needs machine rights and two-factor; declining runs nothing; other workspaces can’t see it', function () {
    [$workspace, $machine] = fixWorkspace();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'needs_approval', 'title' => 'x',
        'actions' => FixActions::clean([['type' => 'restart', 'service' => 'docker:shop', 'why' => 'x']], $machine)]);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->get(fixUrl($workspace))->assertOk();
    $this->actingAs($viewer)->post(fixUrl($workspace, "/{$fix->id}/approve"))->assertForbidden();
    $this->actingAs($viewer)->post(fixUrl($workspace), ['machine' => $machine->id])->assertForbidden();

    $workspace->owner->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAs($workspace->owner)->post(fixUrl($workspace, "/{$fix->id}/approve"))->assertSessionHasErrors('approve');
    expect(DaemonJob::query()->count())->toBe(0);

    $this->actingAs($workspace->owner)->post(fixUrl($workspace, "/{$fix->id}/decline"))->assertRedirect();
    expect($fix->refresh()->state)->toBe('declined')->and(DaemonJob::query()->count())->toBe(0);

    $other = fixWorkspace()[0];
    $this->actingAs($other->owner)->post(fixUrl($other, "/{$fix->id}/decline"))->assertNotFound();
});

test('the page lists what needs you and shows the selected fix', function () {
    [$workspace, $machine] = fixWorkspace();
    AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'fixed', 'title' => 'Old one', 'created_at' => now()->subDay()]);
    $waiting = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'needs_approval', 'title' => 'shop is down', 'confidence' => 'high',
        'actions' => FixActions::clean([['type' => 'start', 'service' => 'docker:shop', 'why' => 'Bring it back.']], $machine)]);

    $this->actingAs($workspace->owner)->get(fixUrl($workspace, "?fix={$waiting->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Fixes')
            ->has('fixes', 2)
            ->where('selected.title', 'shop is down')
            ->where('selected.steps.0.label', 'Start a stopped managed service')
            ->where('can.approve', true)
            ->has('machines.0.services', 3));
});

test('the hour’s allowance is shown, and once it’s used the page says when you can ask again', function () {
    Queue::fake();
    $this->travelTo(now()->setTime(13, 0));
    [$workspace, $machine] = fixWorkspace();
    foreach (range(1, 20) as $i) {
        AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'nothing_to_do'])
            ->forceFill(['created_at' => now()->subMinutes(58 - $i)])->save();
    }

    $this->actingAs($workspace->owner)->get(fixUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('allowance.used', 20)
            ->where('allowance.limit', 20)
            ->where('allowance.ownKey', false)
            ->where('allowance.resetsAt', now()->subMinutes(57)->addHour()->toIso8601String()));

    $this->actingAs($workspace->owner)->post(fixUrl($workspace), ['machine' => $machine->id])
        ->assertSessionHasErrors(['limit' => 'You’ve used this hour’s 20 investigations. You can ask again at 13:03 UTC. Connect your own AI key in Settings › AI and MCP for 100 an hour.']);
    expect(AgentFix::query()->count())->toBe(20);
    Queue::assertNothingPushed();

    // An hour on, the oldest has dropped out and there's room again.
    $this->travel(4)->minutes();
    $this->actingAs($workspace->owner)->post(fixUrl($workspace), ['machine' => $machine->id])->assertSessionHasNoErrors();
    expect(AgentFix::query()->count())->toBe(21);
});

test('on the workspace’s own AI key the allowance is bigger', function () {
    Queue::fake();
    [$workspace, $machine] = fixWorkspace();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'key' => 'sk-ant-theirs', 'hint' => '…eirs']);
    foreach (range(1, 20) as $i) {
        AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'nothing_to_do']);
    }

    $this->actingAs($workspace->owner)->get(fixUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('allowance.limit', 100)->where('allowance.ownKey', true)->where('allowance.resetsAt', null));
    $this->actingAs($workspace->owner)->post(fixUrl($workspace), ['machine' => $machine->id])->assertSessionHasNoErrors();
});

test('asking many times at once is slowed down with words, not a 429', function () {
    Queue::fake();
    [$workspace, $machine] = fixWorkspace();
    foreach (range(1, 5) as $i) {
        $this->actingAs($workspace->owner)->post(fixUrl($workspace), ['machine' => $machine->id])->assertSessionHasNoErrors();
    }

    $this->actingAs($workspace->owner)->post(fixUrl($workspace), ['machine' => $machine->id])
        ->assertRedirect()
        ->assertSessionHasErrors('limit');
    expect(session('errors')->first('limit'))->toStartWith('That’s a lot at once. You can ask again in ')
        ->and(AgentFix::query()->count())->toBe(5);
});
