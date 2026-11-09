<?php

use App\Enums\Plan;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Notifications\WorkflowMessage;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowTemplates;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
    Notification::fake();
    $this->withoutDefer();
});

/**
 * @return array{0: Workspace, 1: Machine}
 */
function tplWorkspace(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine];
}

function tplUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/workflows'.$path;
}

/**
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string}>  $steps
 */
function tplFlow(Workspace $workspace, array $steps): Workflow
{
    $nodes = [];
    $edges = [];
    foreach ($steps as $i => $step) {
        $nodes[] = ['id' => $step[0], 'kind' => $step[1], 'x' => 0, 'y' => $i * 200, 'title' => '', 'description' => '', 'rules' => $step[2]];
        if (isset($step[3])) {
            $edges[] = ['id' => "e{$i}", 'source' => $step[3], 'port' => 'out', 'target' => $step[0]];
        }
    }

    return $workspace->workflows()->create([
        'name' => 'Test flow', 'graph' => WorkflowSteps::clean(['nodes' => $nodes, 'edges' => $edges]),
        'enabled' => true, 'run_as' => $workspace->owner_id, 'trigger' => 'manual',
    ]);
}

/** What each template still needs from a person, in a workspace with a server, app, deploy, monitor and project with a Staging copy. */
const STILL_NEEDS = [
    'laravel-push' => ['Enter the repository, as owner/name.', 'Choose the service the app runs under, like PHP-FPM.'],
    'deploy-notify' => ['Enter the URL to call.'],
    'client-site-down' => ['Add who to email.', 'Add who to email.'],
    'client-deploy-notice' => ['Add who to email.'],
    'blank' => ['Add at least one step after the trigger.'],
    // No Coding agent container in this workspace.
    'agent-fix-deploy' => ['Deploy the Coding agent template to a machine, then choose its container.'],
    'agent-issues' => ['Deploy the Coding agent template to a machine, then choose its container.'],
];

test('every template is for someone, and is ready to turn on once its blanks are filled', function () {
    [$workspace, $machine] = tplWorkspace();
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);
    DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop']]]);
    // And a project with a Staging copy, for the staging templates.
    $project = $workspace->projects()->create(['name' => 'Shop']);
    $project->environments()->create(['name' => 'Staging', 'position' => 1, 'source_id' => $project->production()->id]);

    $list = WorkflowTemplates::list();
    expect(collect($list)->pluck('audience')->unique()->sort()->values()->all())->toBe(['agencies', 'developers', 'everyone', 'new', 'teams']);

    foreach ($list as $template) {
        $graph = WorkflowTemplates::build($template['key'], $workspace)['graph'];
        $problems = array_values(WorkflowSteps::problems($graph, $workspace));

        expect($problems)->toBe(STILL_NEEDS[$template['key']] ?? [], "{$template['key']}: ".implode(' / ', $problems));
        // Every step is connected to the trigger.
        $reached = collect($graph['edges'])->pluck('target')->push(collect($graph['nodes'])->firstWhere('kind', 'trigger')['id']);
        expect(collect($graph['nodes'])->pluck('id')->diff($reached)->all())->toBe([], $template['key']);
    }
});

test('the picker groups templates by who they are for', function () {
    [$workspace] = tplWorkspace();

    $this->actingAs($workspace->owner)->get(tplUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('templates', fn ($t) => collect($t)->where('audience', 'agencies')->count() === 3
                && collect($t)->firstWhere('key', 'page-oncall')['for'] === 'Teams at scale'));
});

test('an alert can email specific people as well as the team', function () {
    [$workspace] = tplWorkspace();
    $workspace->update(['name' => 'Acme Agency']);
    $workflow = tplFlow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['a', 'alert', ['to' => 'both', 'emails' => 'Client@Example.com, boss@example.com', 'subject' => 'Your site is back', 'message' => 'All good.', 'tone' => 'good'], 't'],
    ]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Test', [], $workspace->owner);

    expect($run->state)->toBe('succeeded')
        ->and($run->steps['a']['message'])->toBe('Sent “Your site is back” to the team and client@example.com, boss@example.com');
    Notification::assertSentTo($workspace->owner, MonitorAlert::class);
    Notification::assertSentOnDemand(WorkflowMessage::class, fn (WorkflowMessage $n, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === ['client@example.com', 'boss@example.com']
        && $n->from === 'Acme Agency' && $n->replyTo === $workspace->owner->email && ! $n->bad);
});

test('an alert just to specific people leaves the team out, and checks the addresses', function () {
    [$workspace] = tplWorkspace();
    $workflow = tplFlow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['a', 'alert', ['to' => 'emails', 'emails' => 'client@example.com', 'subject' => 'Updated'], 't'],
    ]);

    app(WorkflowEngine::class)->start($workflow, 'manual', 'Test', [], $workspace->owner);
    Notification::assertNotSentTo($workspace->owner, MonitorAlert::class);
    Notification::assertSentOnDemand(WorkflowMessage::class);

    $bad = fn (string $emails) => WorkflowSteps::problems(WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']],
        ['id' => 'a', 'kind' => 'alert', 'rules' => ['to' => 'emails', 'emails' => $emails, 'subject' => 'Hi']],
    ], 'edges' => [['source' => 't', 'target' => 'a']]]), $workspace)['a'] ?? null;

    expect($bad(''))->toBe('Add who to email.')
        ->and($bad('not-an-email'))->toStartWith('Up to 5 email addresses')
        ->and($bad('a@x.com,b@x.com,c@x.com,d@x.com,e@x.com,f@x.com'))->toStartWith('Up to 5')
        ->and($bad('a@x.com; b@x.com'))->toBeNull();
});

test('backing up every server skips the ones that are offline', function () {
    [$workspace, $online] = tplWorkspace();
    $offline = Machine::factory()->for($workspace)->create(['name' => 'old-box']);
    $offline->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subDay()])->save();
    $workflow = tplFlow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['b', 'backup', ['machine_id' => WorkflowSteps::ALL_MACHINES], 't'],
    ]);

    expect(WorkflowSteps::problems(WorkflowSteps::clean($workflow->graph), $workspace))->toBe([]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Test', [], $workspace->owner);
    $messages = collect($run->logs)->pluck('message')->implode("\n");

    expect($messages)->toContain('Skipped old-box');
    // Without a backup destination set up, the online one has nothing to start either.
    expect($run->steps['b']['state'])->toBeIn(['running', 'failed']);
});

test('every server is only for backups, and needs a server to exist', function () {
    [$workspace] = tplWorkspace();
    $problem = fn (string $kind) => WorkflowSteps::problems(WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']],
        ['id' => 's', 'kind' => $kind, 'rules' => ['machine_id' => WorkflowSteps::ALL_MACHINES, 'service' => 'docker:aaa']],
    ], 'edges' => [['source' => 't', 'target' => 's']]]), $workspace)['s'] ?? null;

    expect($problem('backup'))->toBeNull()
        ->and($problem('restart'))->toBe('Choose a machine.');

    $workspace->machines()->delete();
    expect($problem('backup'))->toBe('Connect a machine first.');
});
