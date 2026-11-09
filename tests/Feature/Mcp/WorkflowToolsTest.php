<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\Tools\Workflow\WorkflowReference;
use App\Mcp\Tools\WorkflowTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('mcp.groups', [WorkflowTools::class]);
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
    Notification::fake();
});

/** An online machine with a managed container, an unmanaged unit and an unmanaged container. */
function wfToolsMachine(Workspace $workspace, string $name = 'web-1'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'details' => []],
            ['key' => 'docker:bbb', 'name' => 'worker', 'kind' => 'app', 'manager' => 'docker', 'status' => 'exited', 'ports' => [], 'details' => []],
        ]],
    ])->save();

    return $machine;
}

/** @return array{0: Workspace, 1: Machine, 2: string} */
function wfToolsSetup(Plan $plan = Plan::Pro, ?User $owner = null): array
{
    $workspace = Workspace::factory()->onPlan($plan)->create(['name' => 'Acme', ...($owner ? ['owner_id' => $owner->id] : [])]);
    $machine = wfToolsMachine($workspace);

    return [$workspace, $machine, mcpKey($workspace)];
}

function wfToolsViewer(Workspace $workspace): string
{
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    return mcpKey($workspace, $viewer);
}

/**
 * A workflow from steps given as [id, kind, rules, from, port].
 *
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string, 4?: string}>  $steps
 */
function wfToolsFlow(Workspace $workspace, array $steps, bool $enabled = false, string $name = 'Test flow'): Workflow
{
    $nodes = [];
    $edges = [];
    foreach ($steps as $i => $step) {
        [$id, $kind, $rules] = $step;
        $nodes[] = ['id' => $id, 'kind' => $kind, 'x' => 0, 'y' => $i * 200, 'title' => '', 'description' => '', 'rules' => $rules];
        if (isset($step[3])) {
            $edges[] = ['id' => "e{$i}", 'source' => $step[3], 'port' => $step[4] ?? 'out', 'target' => $id];
        }
    }

    return $workspace->workflows()->create([
        'name' => $name, 'graph' => WorkflowSteps::clean(['nodes' => $nodes, 'edges' => $edges]), 'enabled' => $enabled,
        'run_as' => $workspace->owner_id, 'trigger' => $steps[0][2]['event'] ?? 'manual',
    ]);
}

/** A restart of shop on the machine, with an alert when it fails. */
function wfToolsRestartFlow(Workspace $workspace, Machine $machine, string $name = 'Restart shop'): Workflow
{
    return wfToolsFlow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 't'],
        ['bad', 'alert', ['subject' => 'Restart failed'], 'r', 'failed'],
    ], name: $name);
}

const WF_TOOLS_READ = ['list_workflows', 'get_workflow', 'get_workflow_reference', 'list_workflow_templates', 'list_workflow_runs', 'get_workflow_run'];

const WF_TOOLS_WRITE = ['create_workflow', 'update_workflow', 'run_workflow', 'cancel_workflow_run', 'delete_workflow'];

test('tools are listed by what the connection and role allow', function () {
    [$workspace, , $key] = wfToolsSetup();

    $all = collect(mcpRequest($workspace, $key, 'tools/list')->json('result.tools'))->keyBy('name');
    expect($all->keys()->all())->toEqualCanonicalizing([...WF_TOOLS_READ, ...WF_TOOLS_WRITE])
        ->and($all['delete_workflow']['annotations']['destructiveHint'])->toBeTrue()
        ->and($all['cancel_workflow_run']['annotations']['destructiveHint'])->toBeTrue()
        ->and($all['update_workflow']['annotations']['destructiveHint'])->toBeFalse()
        ->and($all['get_workflow']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($all['run_workflow']['annotations']['openWorldHint'])->toBeTrue()
        ->and($all['delete_workflow']['inputSchema']['required'])->toContain('confirm');

    $readOnly = collect(mcpRequest($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'tools/list')->json('result.tools'))->pluck('name');
    expect($readOnly->all())->toEqualCanonicalizing(WF_TOOLS_READ);

    $viewer = collect(mcpRequest($workspace, wfToolsViewer($workspace), 'tools/list')->json('result.tools'))->pluck('name');
    expect($viewer->all())->toEqualCanonicalizing(WF_TOOLS_READ);
});

test('list_workflows shows each workflow like the Workflows page', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $monitor = $workspace->monitors()->create(['name' => 'Shop site', 'type' => 'http', 'url' => 'https://shop.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);
    $nightly = wfToolsFlow($workspace, [
        ['t', 'trigger', ['event' => 'schedule', 'every' => 'day', 'at' => '03:00']],
        ['b', 'backup', ['machine_id' => $machine->id], 't'],
    ], enabled: true, name: 'Nightly');
    $nightly->forceFill(['next_run_at' => now()->addHours(3)])->save();
    $down = wfToolsFlow($workspace, [
        ['t', 'trigger', ['event' => 'monitor.down', 'monitor_id' => $monitor->id]],
        ['a', 'alert', ['subject' => 'Down'], 't'],
    ], name: 'Alarm');
    foreach (['succeeded', 'failed'] as $i => $state) {
        $nightly->runs()->create(['state' => $state, 'trigger' => ['kind' => 'schedule', 'summary' => 'On schedule'], 'graph' => $nightly->graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()->subHours(2 - $i)]);
    }
    $nightly->runs()->create(['state' => 'succeeded', 'trigger' => ['kind' => 'schedule', 'summary' => 'Old'], 'graph' => $nightly->graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()->subDays(9)]);

    $result = mcpCall($workspace, $key, 'list_workflows');
    expect($result['error'])->toBeFalse()
        ->and($result['data']['total'])->toBe(2)
        ->and($result['data']['planAllows'])->toBeTrue()
        ->and(collect($result['data']['workflows'])->pluck('name')->all())->toBe(['Alarm', 'Nightly']);

    [$alarm, $row] = $result['data']['workflows'];
    expect($row)->toMatchArray([
        'id' => $nightly->id,
        'enabled' => true,
        'trigger' => 'schedule',
        'triggerWords' => 'Every day at 03:00 UTC',
        'steps' => 1,
        'runs7d' => 2,
        'failed7d' => 1,
    ])->and($row['lastRun']['state'])->toBe('failed')
        ->and($row['nextRunAt'])->not->toBeNull()
        ->and($row['url'])->toEndWith("/workflows/{$nightly->id}")
        ->and($alarm['triggerWords'])->toBe('A monitor goes down: Shop site')
        ->and($alarm['enabled'])->toBeFalse()
        ->and($alarm['nextRunAt'])->toBeNull()
        ->and($alarm['lastRun'])->toBeNull();

    $on = mcpCall($workspace, $key, 'list_workflows', ['enabled' => true]);
    expect(collect($on['data']['workflows'])->pluck('name')->all())->toBe(['Nightly'])
        ->and(collect(mcpCall($workspace, $key, 'list_workflows', ['trigger' => 'monitor.down'])['data']['workflows'])->pluck('id')->all())->toBe([$down->id]);
});

test('get_workflow returns the graph, the steps in words and the problems, never the webhook URL', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [
        ['t', 'trigger', ['event' => 'webhook']],
        ['b', 'branch', ['condition' => 'trigger_field', 'field' => 'ref', 'value' => 'refs/heads/main'], 't'],
        ['r', 'restart', ['machine_id' => $machine->id, 'service' => 'docker:aaa'], 'b', 'true'],
        ['s', 'stop', ['machine_id' => $machine->id, 'service' => 'systemd:nginx.service'], 'r'],
        ['a', 'alert', ['subject' => '', 'to' => 'emails', 'emails' => 'client@example.com'], 'r', 'failed'],
        ['loose', 'wait', ['minutes' => 5]],
    ], name: 'CI');
    $token = $workflow->rotateWebhook();
    $workflow->save();

    $result = mcpCall($workspace, $key, 'get_workflow', ['workflow' => 'ci']);
    $data = $result['data'];
    expect($result['error'])->toBeFalse()
        ->and($result['text'])->not->toContain($token)
        ->and($result['text'])->not->toContain('api/hooks')
        ->and($data['id'])->toBe($workflow->id)
        ->and($data['trigger'])->toBe(['event' => 'webhook', 'label' => 'Webhook', 'words' => 'Webhook'])
        ->and($data['webhook']['hasUrl'])->toBeTrue()
        ->and($data['runAs'])->toBe($workspace->owner->name)
        ->and($data['graph']['nodes'])->toHaveCount(6)
        ->and($data['graph']['edges'][0])->toMatchArray(['source' => 't', 'port' => 'out', 'target' => 'b'])
        ->and($data['canTurnOn'])->toBeFalse();

    $steps = collect($data['steps'])->keyBy('id');
    expect(collect($data['steps'])->pluck('id')->all())->toBe(['t', 'b', 'r', 's', 'a', 'loose'])
        ->and($steps['b']['does'])->toBe('Is ref “refs/heads/main”?')
        ->and($steps['b']['next'])->toBe(['true' => ['r'], 'false' => []])
        ->and($steps['r']['does'])->toBe('Restart shop on web-1')
        ->and($steps['r']['next'])->toBe(['out' => ['s'], 'failed' => ['a']])
        ->and($steps['a']['does'])->toBe('Email client@example.com: “…”')
        ->and($steps['a']['problem'])->toBe('Write what the alert says.')
        ->and($steps['loose']['connected'])->toBeFalse()
        ->and($data['problems'])->toBe([['step' => 'a', 'title' => 'alert', 'problem' => 'Write what the alert says.']])
        ->and(collect($data['hints'])->pluck('hint')->all())->toContain('nginx isn’t managed by ServerOS yet, so this step would fail; adopt it first.', 'Nothing leads to this step, so it never runs.');

    // By id too; and only in this workspace.
    expect(mcpCall($workspace, $key, 'get_workflow', ['workflow' => (string) $workflow->id])['data']['name'])->toBe('CI');
    [$other, , $otherKey] = wfToolsSetup();
    $missing = mcpCall($other, $otherKey, 'get_workflow', ['workflow' => $workflow->id]);
    expect($missing['error'])->toBeTrue()->and($missing['data']['error'])->toContain('No workflow');

    // A viewer sees it too.
    expect(mcpCall($workspace, wfToolsViewer($workspace), 'get_workflow', ['workflow' => $workflow->id])['error'])->toBeFalse();
});

test('get_workflow_reference matches what the server accepts, and its example is a working graph', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workspace->monitors()->create(['name' => 'Shop site', 'type' => 'http', 'url' => 'https://shop.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    $data = mcpCall($workspace, $key, 'get_workflow_reference')['data'];
    expect(collect($data['events'])->pluck('event')->all())->toBe(WorkflowSteps::EVENTS)
        ->and(collect($data['kinds'])->mapWithKeys(fn ($k) => [$k['kind'] => $k['ports']])->all())->toBe(WorkflowSteps::PORTS)
        ->and(collect($data['conditions'])->pluck('condition')->all())->toBe(WorkflowSteps::CONDITIONS)
        ->and($data['limits']['nodes'])->toBe(WorkflowSteps::MAX_NODES)
        ->and($data['limits']['edges'])->toBe(WorkflowSteps::MAX_EDGES)
        ->and($data['placeholders']['values'])->toHaveKeys(['{{service}}', '{{machine}}', '{{run_url}}'])
        ->and($data['specialValues'])->toHaveKeys(['@trigger', '@all'])
        ->and(collect($data['events'])->firstWhere('event', 'schedule')['filters']['every']['values'])->toBe(['hour', 'day', 'week'])
        ->and(collect($data['kinds'])->firstWhere('kind', 'alert')['rules']['to']['values'])->toBe(WorkflowSteps::ALERT_TO)
        ->and($data['choices']['machines'][0])->toMatchArray(['id' => $machine->id, 'name' => 'web-1'])
        ->and($data['choices']['machines'][0]['services'][0])->toBe(['key' => 'docker:aaa', 'name' => 'shop', 'managed' => true, 'container' => true])
        ->and($data['choices']['monitors'][0]['name'])->toBe('Shop site');

    // Every rule the editor sets for each kind is described.
    foreach (['redeploy' => ['machine_id', 'service'], 'command' => ['machine_id', 'service', 'command'], 'backup' => ['machine_id'], 'check_monitor' => ['monitor_id', 'timeout'], 'http' => ['method', 'url', 'body'], 'alert' => ['subject', 'message', 'tone', 'to', 'emails'], 'wait' => ['minutes']] as $kind => $rules) {
        expect(array_keys(collect($data['kinds'])->firstWhere('kind', $kind)['rules']))->toBe($rules);
    }

    expect(WorkflowSteps::problems(WorkflowSteps::clean(WorkflowReference::example()), $workspace))->toBe([])
        ->and(mcpCall($workspace, $key, 'get_workflow_reference', ['include_choices' => false])['data']['choices'])->toBeNull();

    // The example saves and turns on as it is.
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']]]);
    $saved = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'graph' => $data['example'], 'enabled' => true]);
    expect($saved['error'])->toBeFalse()
        ->and($saved['data']['workflow']['enabled'])->toBeTrue()
        ->and($workflow->fresh()->trigger)->toBe('service.down');
});

test('list_workflow_templates lists the picker’s templates', function () {
    [$workspace, , $key] = wfToolsSetup();

    $data = mcpCall($workspace, $key, 'list_workflow_templates')['data'];
    expect(collect($data['templates'])->pluck('key')->all())->toBe(array_column(WorkflowTemplates::list(), 'key'))
        ->and($data['planAllows'])->toBeTrue()
        ->and($data['limit'])->toBe(100);

    [$essential, , $essentialKey] = wfToolsSetup(Plan::Essential);
    expect(mcpCall($essential, $essentialKey, 'list_workflow_templates')['data']['planAllows'])->toBeFalse();
});

test('create_workflow makes one from a template, off, credited via MCP', function () {
    [$workspace, , $key] = wfToolsSetup();

    $result = mcpCall($workspace, $key, 'create_workflow', ['template' => 'ci-deploy', 'name' => 'Ship it']);
    $workflow = Workflow::query()->sole();
    expect($result['error'])->toBeFalse()
        ->and($workflow->name)->toBe('Ship it')
        ->and($workflow->enabled)->toBeFalse()
        ->and($workflow->trigger)->toBe('webhook')
        ->and($workflow->run_as)->toBe($workspace->owner_id)
        ->and($workflow->webhook_token)->toStartWith('wh_')
        ->and($result['text'])->not->toContain($workflow->webhook_token)
        ->and($result['data']['workflow']['webhook']['hasUrl'])->toBeTrue()
        ->and($result['data']['workflow']['problems'])->not->toBe([])
        ->and($result['data']['note'])->toContain('It’s off');

    $activity = Activity::query()->where('action', 'workflow.add')->sole();
    expect($activity->subject)->toBe('Ship it')
        ->and($activity->user_id)->toBe($workspace->owner_id)
        ->and($activity->meta['via'])->toBe('mcp');

    $blank = mcpCall($workspace, $key, 'create_workflow', ['template' => 'blank']);
    expect($blank['data']['workflow']['name'])->toBe('Untitled workflow')
        ->and($blank['data']['workflow']['problems'][0]['problem'])->toBe('Add at least one step after the trigger.');

    $bad = mcpCall($workspace, $key, 'create_workflow', ['template' => 'nope']);
    expect($bad['error'])->toBeTrue()->and($bad['data']['error'])->toContain('The template must be one of');
});

test('create_workflow needs Pro, and stops at 100', function () {
    [$essential, , $essentialKey] = wfToolsSetup(Plan::Essential);
    $denied = mcpCall($essential, $essentialKey, 'create_workflow', ['template' => 'blank']);
    expect($denied['error'])->toBeTrue()
        ->and($denied['data']['error'])->toBe('Workflows come with Pro. Choose Pro or Business to use them.')
        ->and(Workflow::query()->count())->toBe(0);

    [$workspace, , $key] = wfToolsSetup();
    $graph = WorkflowTemplates::build('blank', $workspace)['graph'];
    foreach (range(1, 100) as $i) {
        $workspace->workflows()->create(['name' => "W{$i}", 'graph' => $graph, 'trigger' => 'manual']);
    }
    $full = mcpCall($workspace, $key, 'create_workflow', ['template' => 'blank']);
    expect($full['error'])->toBeTrue()->and($full['data']['error'])->toBe('A workspace can have 100 workflows.');

    // Viewers and read-only keys can't.
    expect(mcpCall($workspace, wfToolsViewer($workspace), 'create_workflow', ['template' => 'blank'])['data']['error'])->toContain('doesn’t allow that')
        ->and(mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'create_workflow', ['template' => 'blank'])['data']['error'])->toContain('read-only');
});

test('update_workflow replaces the graph as the editor saves it, laying out new steps', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']]], name: 'Draft');
    $admin = User::factory()->create();
    $workspace->members()->attach($admin, ['role' => 'admin']);

    $result = mcpCall($workspace, mcpKey($workspace, $admin), 'update_workflow', [
        'workflow' => 'Draft',
        'name' => '  Keep shop up ',
        'description' => 'Restarts shop',
        'graph' => [
            'nodes' => [
                ['id' => 'start', 'kind' => 'trigger', 'rules' => ['event' => 'manual']],
                ['id' => 'restart', 'kind' => 'restart', 'rules' => ['machine_id' => $machine->id, 'service' => 'docker:aaa']],
                ['id' => 'ok', 'kind' => 'alert', 'title' => 'All good', 'rules' => ['subject' => 'Restarted {{service}}', 'tone' => 'good']],
                ['id' => 'bad', 'kind' => 'alert', 'rules' => ['subject' => 'Restart failed']],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'restart'],
                ['source' => 'restart', 'port' => 'out', 'target' => 'ok'],
                ['source' => 'restart', 'port' => 'failed', 'target' => 'bad'],
            ],
        ],
    ]);

    $workflow->refresh();
    $nodes = collect($workflow->graph['nodes'])->keyBy('id');
    expect($result['error'])->toBeFalse()
        ->and($result['data']['changed'])->toEqualCanonicalizing(['name', 'description', 'graph'])
        ->and($workflow->name)->toBe('Keep shop up')
        ->and($workflow->description)->toBe('Restarts shop')
        ->and($workflow->enabled)->toBeFalse()
        ->and($workflow->run_as)->toBe($admin->id)
        ->and($workflow->graph['edges'])->toHaveCount(3)
        ->and($nodes['restart']['title'])->toBe('Restart a service')
        ->and($nodes['ok']['title'])->toBe('All good')
        ->and([$nodes['start']['y'], $nodes['restart']['y'], $nodes['ok']['y']])->toBe([0, 200, 400])
        ->and($nodes['ok']['x'])->not->toBe($nodes['bad']['x'])
        ->and($result['data']['workflow']['problems'])->toBe([])
        ->and($result['data']['workflow']['runAs'])->toBe($admin->name);

    $activity = Activity::query()->where('action', 'workflow.update')->sole();
    expect($activity->user_id)->toBe($admin->id)->and($activity->meta['via'])->toBe('mcp');

    // Turning it on and off is recorded as the dashboard records it.
    expect(mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'enabled' => true])['data']['note'])->toContain('is on');
    expect($workflow->fresh()->enabled)->toBeTrue();
    mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'enabled' => false]);
    expect($workflow->fresh()->enabled)->toBeFalse()
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['workflow.update', 'workflow.enable', 'workflow.disable'])
        ->and($workflow->fresh()->run_as)->toBe($workspace->owner_id);

    $nothing = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id]);
    expect($nothing['error'])->toBeTrue()->and($nothing['data']['error'])->toContain('Give what to change');
});

test('update_workflow won’t turn on a workflow with problems, or on a plan without workflows', function () {
    [$workspace, , $key] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [
        ['t', 'trigger', ['event' => 'manual']],
        ['r', 'restart', ['machine_id' => '', 'service' => ''], 't'],
    ]);

    $result = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'enabled' => true]);
    expect($result['error'])->toBeTrue()
        ->and($result['data']['error'])->toContain('Fix the steps marked with a warning before turning it on.')
        ->and($result['data']['error'])->toContain('Choose a machine.')
        ->and($workflow->fresh()->enabled)->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);

    // A draft with problems still saves while it's off.
    $draft = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'name' => 'Draft']);
    expect($draft['error'])->toBeFalse()
        ->and($draft['data']['workflow']['problems'][0])->toBe(['step' => 'r', 'title' => 'restart', 'problem' => 'Choose a machine.'])
        ->and($draft['data']['note'])->toContain('fix its problems');

    // An enabled workflow stays on only without problems.
    $on = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']], ['a', 'alert', ['subject' => 'x'], 't']], enabled: true, name: 'On');
    $broken = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $on->id, 'graph' => ['nodes' => [['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']], ['id' => 'a', 'kind' => 'alert', 'rules' => []]], 'edges' => [['source' => 't', 'target' => 'a']]]]);
    expect($broken['error'])->toBeTrue()->and($broken['data']['error'])->toContain('pass enabled false');

    [$essential, $machine, $essentialKey] = wfToolsSetup(Plan::Essential);
    $ok = wfToolsFlow($essential, [['t', 'trigger', ['event' => 'manual']], ['a', 'alert', ['subject' => 'x'], 't']]);
    $plan = mcpCall($essential, $essentialKey, 'update_workflow', ['workflow' => $ok->id, 'enabled' => true]);
    expect($plan['error'])->toBeTrue()
        ->and($plan['data']['error'])->toBe('Workflows come with Pro. Choose Pro or Business to turn one on.')
        ->and(mcpCall($essential, $essentialKey, 'update_workflow', ['workflow' => $ok->id, 'name' => 'Still off'])['error'])->toBeFalse();
});

test('update_workflow tells the agent what is wrong with a graph instead of dropping it', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']], ['a', 'alert', ['subject' => 'x'], 't']]);
    $before = $workflow->graph;

    $result = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'graph' => [
        'nodes' => [
            ['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']],
            ['id' => 't2', 'kind' => 'trigger'],
            ['id' => 'x', 'kind' => 'teleport'],
            ['id' => 'a', 'kind' => 'alert', 'rules' => ['subject' => ['nested']]],
            ['id' => 'a', 'kind' => 'wait'],
            ['id' => 'bad id!', 'kind' => 'wait'],
        ],
        'edges' => [
            ['source' => 't', 'port' => 'failed', 'target' => 'a'],
            ['source' => 't', 'target' => 'ghost'],
            ['source' => 'a', 'target' => 't'],
        ],
    ]]);

    expect($result['error'])->toBeTrue()
        ->and($result['data']['error'])->toContain('The graph wasn’t saved')
        ->and($result['data']['error'])->toContain('one trigger')
        ->and($result['data']['error'])->toContain('kind must be one of')
        ->and($result['data']['error'])->toContain('rules.subject must be a string')
        ->and($result['data']['error'])->toContain('Two steps have the id “a”')
        ->and($result['data']['error'])->toContain('nodes[5].id')
        ->and($result['data']['error'])->toContain('a trigger step leaves by out, not “failed”')
        ->and($result['data']['error'])->toContain('target must be the id of a step')
        ->and($result['data']['error'])->toContain('nothing can lead into the trigger')
        ->and($workflow->fresh()->graph)->toBe($before);

    $notObject = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'graph' => ['nodes' => 'x']]);
    expect($notObject['error'])->toBeTrue()->and($notObject['data']['error'])->toContain('graph.nodes must be a list');

    $tooMany = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'graph' => ['nodes' => array_map(fn ($i) => ['id' => "n{$i}", 'kind' => 'wait'], range(1, 61)), 'edges' => []]]);
    expect($tooMany['data']['error'])->toContain('up to 60 steps');
});

test('update_workflow sets the schedule’s next run and makes a webhook URL it never shows', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $this->travelTo(now()->setTime(2, 0));
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']], ['b', 'backup', ['machine_id' => $machine->id], 't']]);

    $scheduled = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'enabled' => true, 'graph' => [
        'nodes' => [['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'schedule', 'every' => 'day', 'at' => '03:30']], ['id' => 'b', 'kind' => 'backup', 'rules' => ['machine_id' => $machine->id]]],
        'edges' => [['source' => 't', 'target' => 'b']],
    ]]);
    expect($scheduled['error'])->toBeFalse()
        ->and($workflow->fresh()->next_run_at->format('H:i'))->toBe('03:30')
        ->and($scheduled['data']['workflow']['trigger']['words'])->toBe('Every day at 03:30 UTC')
        ->and($scheduled['data']['workflow']['nextRunAt'])->not->toBeNull();

    $hooked = mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'graph' => [
        'nodes' => [['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'webhook']], ['id' => 'b', 'kind' => 'backup', 'rules' => ['machine_id' => $machine->id]]],
        'edges' => [['source' => 't', 'target' => 'b']],
    ]]);
    $workflow->refresh();
    expect($hooked['error'])->toBeFalse()
        ->and($workflow->trigger)->toBe('webhook')
        ->and($workflow->next_run_at)->toBeNull()
        ->and($workflow->webhook_token)->toStartWith('wh_')
        ->and($hooked['text'])->not->toContain($workflow->webhook_token)
        ->and($hooked['data']['workflow']['webhook']['hasUrl'])->toBeTrue();

    // Saving again keeps the same URL.
    $token = $workflow->webhook_token;
    mcpCall($workspace, $key, 'update_workflow', ['workflow' => $workflow->id, 'name' => 'Hooked']);
    expect($workflow->fresh()->webhook_token)->toBe($token);
});

test('workflows with commands need two-factor and a connection that may run commands', function () {
    [$workspace, $machine] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']]]);
    $graph = [
        'nodes' => [
            ['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']],
            ['id' => 'c', 'kind' => 'command', 'rules' => ['machine_id' => $machine->id, 'service' => 'docker:aaa', 'command' => 'php artisan migrate --force']],
        ],
        'edges' => [['source' => 't', 'target' => 'c']],
    ];

    $writeOnly = mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ, Scopes::WRITE]), 'update_workflow', ['workflow' => $workflow->id, 'graph' => $graph]);
    expect($writeOnly['error'])->toBeTrue()
        ->and($writeOnly['data']['error'])->toContain('needs a connection allowed to run commands')
        ->and(WorkflowSteps::hasCommand($workflow->fresh()->graph))->toBeFalse();

    $noTwoFactor = mcpCall($workspace, mcpKey($workspace), 'update_workflow', ['workflow' => $workflow->id, 'graph' => $graph]);
    expect($noTwoFactor['error'])->toBeTrue()
        ->and($noTwoFactor['data']['error'])->toBe('Workflows that run commands run them as you, so turn on two-factor authentication first.');

    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $saved = mcpCall($workspace, mcpKey($workspace), 'update_workflow', ['workflow' => $workflow->id, 'graph' => $graph]);
    expect($saved['error'])->toBeFalse()
        ->and(WorkflowSteps::hasCommand($workflow->fresh()->graph))->toBeTrue()
        ->and(collect($saved['data']['workflow']['steps'])->firstWhere('id', 'c')['does'])->toBe('Run “php artisan migrate --force” in shop on web-1');

    // Running it is for those connections too.
    $run = mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ, Scopes::WRITE]), 'run_workflow', ['workflow' => $workflow->id]);
    expect($run['error'])->toBeTrue()
        ->and($run['data']['error'])->toContain('so running it needs a connection allowed to run commands')
        ->and(WorkflowRun::query()->count())->toBe(0);
});

test('run_workflow runs it now like the Run button, and the run can be followed', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workflow = wfToolsRestartFlow($workspace, $machine);

    $result = mcpCall($workspace, $key, 'run_workflow', ['workflow' => 'Restart shop']);
    $run = WorkflowRun::query()->sole();
    $job = DaemonJob::query()->sole();

    expect($result['error'])->toBeFalse()
        ->and($result['data']['run']['id'])->toBe($run->id)
        ->and($result['data']['run']['state'])->toBe('running')
        ->and($result['data']['run']['trigger'])->toMatchArray(['kind' => 'manual', 'summary' => 'Run by '.$workspace->owner->name])
        ->and($result['data']['run']['trigger']['data'])->toMatchArray(['test' => true, 'machine' => 'web-1', 'service_key' => 'docker:aaa'])
        ->and($result['data']['run']['user'])->toBe($workspace->owner->name)
        ->and(collect($result['data']['run']['steps'])->pluck('state', 'id')->all())->toBe(['t' => 'succeeded', 'r' => 'running'])
        ->and(collect($result['data']['run']['steps'])->firstWhere('id', 'r')['jobs'])->toBe([$job->id])
        ->and($result['data']['run']['notReached'])->toBe(['bad'])
        ->and($result['data']['note'])->toContain('still going')
        ->and($job->only(['kind', 'service', 'workflow_run_id']))->toBe(['kind' => 'service_action', 'service' => 'docker:aaa', 'workflow_run_id' => $run->id])
        ->and($run->user_id)->toBe($workspace->owner_id);

    $activity = Activity::query()->where('action', 'workflow.run')->sole();
    expect($activity->meta)->toMatchArray(['run' => $run->id, 'trigger' => 'manual', 'via' => 'mcp']);

    // The server reports back; the run follows the outcome.
    $this->withoutDefer();
    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'failed', 'log' => ['line one'], 'error' => ['message' => 'container not found']]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $followed = mcpCall($workspace, $key, 'get_workflow_run', ['run' => $run->id, 'logs' => 50]);
    $steps = collect($followed['data']['steps'])->keyBy('id');
    expect($followed['error'])->toBeFalse()
        ->and($followed['data']['state'])->toBe('succeeded')
        ->and($followed['data']['finished'])->toBeTrue()
        ->and($followed['data']['workflow'])->toBe(['id' => $workflow->id, 'name' => 'Restart shop'])
        ->and($steps['r'])->toMatchArray(['state' => 'failed', 'message' => 'container not found', 'leftBy' => 'failed'])
        ->and($steps['bad']['state'])->toBe('succeeded')
        ->and($followed['data']['notReached'])->toBe([])
        ->and(collect($followed['data']['logs'])->pluck('message')->all())->toContain('line one')
        ->and($followed['data']['logs'][0]['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

    // Viewers can follow runs too.
    expect(mcpCall($workspace, wfToolsViewer($workspace), 'get_workflow_run', ['run' => $run->id])['error'])->toBeFalse();
});

test('run_workflow waits for a run that finishes on its own', function () {
    [$workspace, , $key] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'webhook']], ['b', 'branch', ['condition' => 'trigger_field', 'field' => 'ref', 'value' => 'refs/heads/main'], 't'], ['a', 'alert', ['subject' => 'Main {{data.ref}}'], 'b', 'true']]);

    $result = mcpCall($workspace, $key, 'run_workflow', ['workflow' => $workflow->id, 'wait' => 5]);
    expect($result['data']['run']['state'])->toBe('succeeded')
        ->and($result['data']['note'])->toBe("Run #{$result['data']['run']['id']} succeeded.")
        ->and(collect($result['data']['run']['steps'])->firstWhere('id', 'b')['leftBy'])->toBe('true')
        ->and(collect($result['data']['run']['steps'])->firstWhere('id', 'a')['message'])->toBe('Sent “Main refs/heads/main” to the team');
});

test('run_workflow refuses what the Run button refuses', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $broken = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']], ['r', 'restart', ['machine_id' => $machine->id, 'service' => ''], 't']], name: 'Broken');
    $result = mcpCall($workspace, $key, 'run_workflow', ['workflow' => $broken->id]);
    expect($result['error'])->toBeTrue()
        ->and($result['data']['error'])->toBe('Fix the steps marked with a warning first. r (“restart”): Choose a service.');

    $ok = wfToolsRestartFlow($workspace, $machine);
    foreach (range(1, Workflow::RUNS_PER_HOUR) as $i) {
        $ok->runs()->create(['state' => 'succeeded', 'trigger' => ['kind' => 'manual', 'summary' => 'x'], 'graph' => $ok->graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()->subMinutes(5)]);
    }
    $limited = mcpCall($workspace, $key, 'run_workflow', ['workflow' => $ok->id]);
    expect($limited['error'])->toBeTrue()->and($limited['data']['error'])->toBe('It has run 30 times in the last hour; try again later.');

    [$essential, $other, $essentialKey] = wfToolsSetup(Plan::Essential);
    $flow = wfToolsRestartFlow($essential, $other);
    expect(mcpCall($essential, $essentialKey, 'run_workflow', ['workflow' => $flow->id])['data']['error'])->toBe('Workflows come with Pro.');

    expect(mcpCall($workspace, wfToolsViewer($workspace), 'run_workflow', ['workflow' => $ok->id])['data']['error'])->toContain('doesn’t allow that')
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('a test run only ever pretends to be about this workspace’s machines', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    [, $foreign] = wfToolsSetup();
    $foreign->update(['name' => 'their-secret-box']);
    $workflow = wfToolsFlow($workspace, [
        ['t', 'trigger', ['event' => 'service.down', 'machine_id' => $foreign->id]],
        ['a', 'alert', ['subject' => '{{service}} on {{machine}}'], 't'],
    ]);

    $result = mcpCall($workspace, $key, 'run_workflow', ['workflow' => $workflow->id]);
    expect($result['data']['run']['trigger']['data'])->toMatchArray(['machine_id' => $machine->id, 'machine' => 'web-1'])
        ->and($result['text'])->not->toContain('their-secret-box');
});

test('list_workflow_runs lists runs newest first, by workflow, state and page', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $a = wfToolsRestartFlow($workspace, $machine, 'A');
    $b = wfToolsRestartFlow($workspace, $machine, 'B');
    $make = fn (Workflow $w, string $state, array $steps = []) => $w->runs()->create(['state' => $state, 'trigger' => ['kind' => 'schedule', 'summary' => 'On schedule'], 'graph' => $w->graph, 'steps' => $steps, 'frontier' => [], 'logs' => [], 'started_at' => now(), 'finished_at' => $state === 'running' ? null : now()]);
    $first = $make($a, 'succeeded');
    $failed = $make($a, 'failed', ['r' => ['state' => 'failed', 'message' => 'container not found', 'unhandled' => true]]);
    $other = $make($b, 'running');
    [$elsewhere, $theirMachine] = wfToolsSetup();
    $make(wfToolsRestartFlow($elsewhere, $theirMachine), 'failed');

    $all = mcpCall($workspace, $key, 'list_workflow_runs');
    expect(collect($all['data']['runs'])->pluck('id')->all())->toBe([$other->id, $failed->id, $first->id])
        ->and($all['data']['runs'][0]['workflow'])->toBe('B')
        ->and($all['data']['runs'][1]['failedBecause'])->toBe('container not found')
        ->and($all['data']['more'])->toBeFalse();

    expect(collect(mcpCall($workspace, $key, 'list_workflow_runs', ['workflow' => 'A'])['data']['runs'])->pluck('id')->all())->toBe([$failed->id, $first->id])
        ->and(collect(mcpCall($workspace, $key, 'list_workflow_runs', ['state' => 'failed'])['data']['runs'])->pluck('id')->all())->toBe([$failed->id]);

    $page = mcpCall($workspace, $key, 'list_workflow_runs', ['limit' => 2]);
    expect($page['data']['more'])->toBeTrue()->and($page['data']['nextBefore'])->toBe($failed->id);
    expect(collect(mcpCall($workspace, $key, 'list_workflow_runs', ['before' => $failed->id])['data']['runs'])->pluck('id')->all())->toBe([$first->id]);
});

test('runs are only found in this workspace, and under the workflow named', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $a = wfToolsRestartFlow($workspace, $machine, 'A');
    $b = wfToolsRestartFlow($workspace, $machine, 'B');
    $run = $a->runs()->create(['state' => 'succeeded', 'trigger' => ['kind' => 'manual', 'summary' => 'x'], 'graph' => $a->graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()]);
    [$other, , $otherKey] = wfToolsSetup();

    $wrongWorkspace = mcpCall($other, $otherKey, 'get_workflow_run', ['run' => $run->id]);
    expect($wrongWorkspace['error'])->toBeTrue()->and($wrongWorkspace['data']['error'])->toContain("No run #{$run->id}");

    $wrongWorkflow = mcpCall($workspace, $key, 'get_workflow_run', ['run' => $run->id, 'workflow' => $b->id]);
    expect($wrongWorkflow['error'])->toBeTrue()->and($wrongWorkflow['data']['error'])->toBe("Run #{$run->id} isn’t a run of “B”.");

    expect(mcpCall($other, $otherKey, 'cancel_workflow_run', ['run' => $run->id, 'confirm' => 'A'])['data']['error'])->toContain("No run #{$run->id}");
});

test('cancel_workflow_run stops a run after the person confirms', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workflow = wfToolsFlow($workspace, [['t', 'trigger', ['event' => 'manual']], ['w', 'wait', ['minutes' => 10], 't'], ['a', 'alert', ['subject' => 'x'], 'w']], name: 'Slow');
    $runId = mcpCall($workspace, $key, 'run_workflow', ['workflow' => $workflow->id])['data']['run']['id'];
    expect(WorkflowRun::query()->find($runId)->steps['w']['state'])->toBe('waiting');

    $unconfirmed = mcpCall($workspace, $key, 'cancel_workflow_run', ['run' => $runId]);
    expect($unconfirmed['error'])->toBeTrue()
        ->and($unconfirmed['data']['error'])->toContain('“Slow”')
        ->and(WorkflowRun::query()->find($runId)->state)->toBe('running');
    expect(mcpCall($workspace, $key, 'cancel_workflow_run', ['run' => $runId, 'confirm' => 'slow'])['data']['error'])->toContain('doesn’t match');

    $cancelled = mcpCall($workspace, $key, 'cancel_workflow_run', ['run' => $runId, 'confirm' => 'Slow']);
    $run = WorkflowRun::query()->find($runId);
    expect($cancelled['error'])->toBeFalse()
        ->and($run->state)->toBe('cancelled')
        ->and($run->steps['w']['state'])->toBe('cancelled')
        ->and($cancelled['data']['run']['state'])->toBe('cancelled')
        ->and(collect($run->logs)->last()['message'])->toBe("Cancelled by {$workspace->owner->name}.");

    $again = mcpCall($workspace, $key, 'cancel_workflow_run', ['run' => $runId]);
    expect($again['error'])->toBeFalse()->and($again['data']['note'])->toContain('already cancelled');

    expect(mcpCall($workspace, wfToolsViewer($workspace), 'cancel_workflow_run', ['run' => $runId, 'confirm' => 'Slow'])['data']['error'])->toContain('doesn’t allow that');
});

test('delete_workflow needs the name typed, and is recorded', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $workflow = wfToolsRestartFlow($workspace, $machine, 'Old one');
    $workflow->runs()->create(['state' => 'succeeded', 'trigger' => ['kind' => 'manual', 'summary' => 'x'], 'graph' => $workflow->graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()]);

    $unconfirmed = mcpCall($workspace, $key, 'delete_workflow', ['workflow' => $workflow->id]);
    expect($unconfirmed['error'])->toBeTrue()->and($unconfirmed['data']['error'])->toContain('confirm set to the workflow’s name exactly: “Old one”');
    expect(mcpCall($workspace, $key, 'delete_workflow', ['workflow' => $workflow->id, 'confirm' => 'Old'])['data']['error'])->toContain('doesn’t match')
        ->and(Workflow::query()->count())->toBe(1);

    [$other, , $otherKey] = wfToolsSetup();
    expect(mcpCall($other, $otherKey, 'delete_workflow', ['workflow' => $workflow->id, 'confirm' => 'Old one'])['data']['error'])->toContain('No workflow')
        ->and(mcpCall($workspace, wfToolsViewer($workspace), 'delete_workflow', ['workflow' => $workflow->id, 'confirm' => 'Old one'])['data']['error'])->toContain('doesn’t allow that');

    $deleted = mcpCall($workspace, $key, 'delete_workflow', ['workflow' => 'old one', 'confirm' => 'Old one']);
    expect($deleted['error'])->toBeFalse()
        ->and($deleted['data']['deleted'])->toBe(['id' => $workflow->id, 'name' => 'Old one'])
        ->and(Workflow::query()->count())->toBe(0)
        ->and(WorkflowRun::query()->count())->toBe(0);

    $activity = Activity::query()->where('action', 'workflow.remove')->sole();
    expect($activity->subject)->toBe('Old one')->and($activity->meta['via'])->toBe('mcp');
});

test('workflows are found by name, and several with one name need the id', function () {
    [$workspace, $machine, $key] = wfToolsSetup();
    $one = wfToolsRestartFlow($workspace, $machine, 'Twin');
    $two = wfToolsRestartFlow($workspace, $machine, 'Twin');

    $ambiguous = mcpCall($workspace, $key, 'get_workflow', ['workflow' => 'twin']);
    expect($ambiguous['error'])->toBeTrue()->and($ambiguous['data']['error'])->toBe("Several workflows are called “twin”: #{$one->id}, #{$two->id}. Use the id.");

    $close = mcpCall($workspace, $key, 'get_workflow', ['workflow' => 'tw']);
    expect($close['data']['error'])->toContain('Did you mean: Twin, Twin?');

    expect(mcpCall($workspace, $key, 'get_workflow', ['workflow' => ['x']])['data']['error'])->toContain('must be an id or a name');
});
