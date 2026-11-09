<?php

use App\Enums\Plan;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectNode;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Projects\ProjectData;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowInputs;
use App\Support\Workflows\WorkflowSteps;
use App\Support\Workflows\WorkflowTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
    Notification::fake();
    $this->withoutDefer();
});

/**
 * A Pro workspace with one machine running Production (shop and its
 * Postgres) and Staging (copies of both, the app a commit ahead).
 *
 * @return array{0: Workspace, 1: Machine, 2: Project, 3: ProjectEnvironment, 4: ProjectEnvironment}
 */
function wfpSetup(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => '0.1.1-pre.40',
        'facts' => ['docker_version' => '27.3.1', 'interfaces' => [['name' => 'docker0', 'addresses' => ['172.17.0.1/16']]]],
        'inventory' => ['services' => [
            ['key' => 'docker:copy1', 'name' => 'serveros-shop-db-staging-postgres-1', 'kind' => 'container', 'status' => 'running', 'details' => ['compose_project' => 'serveros-shop-db-staging']],
        ]],
    ])->save();

    $project = $workspace->projects()->create(['name' => 'Shop']);
    $production = $project->production();
    $staging = $project->environments()->create(['name' => 'Staging', 'position' => 1, 'source_id' => $production->id]);

    $postgres = app(ServiceTemplateCatalog::class)->find('postgres');
    foreach (['shop-db' => 's3cret', 'shop-db-staging' => 'other'] as $name => $password) {
        $spec = (new TemplateDeployment($postgres, $machine, ['service' => $name, 'port' => $name === 'shop-db' ? 5432 : 5433, 'env' => ['POSTGRES_PASSWORD' => $password]]))->job();
        wfpDeploy($machine, $name, json_decode(json_encode($spec), true));
    }
    wfpDeploy($machine, 'shop', ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'env' => ['APP_ENV' => 'production'], 'domains' => ['shop.example.com'], 'port' => 3000]);
    wfpDeploy($machine, 'shop-staging', ['type' => 'deploy', 'service' => 'shop-staging', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('b', 40), 'env' => ['APP_ENV' => 'staging'], 'domains' => [], 'port' => 3000]);

    $node = fn (ProjectEnvironment $env, string $name, string $kind, ?string $template, ?ProjectNode $origin) => tap(new ProjectNode, fn (ProjectNode $n) => $n->forceFill([
        'project_id' => $project->id, 'environment_id' => $env->id, 'machine_id' => $machine->id,
        'name' => $name, 'kind' => $kind, 'template' => $template, 'origin_id' => $origin?->id,
    ])->save());
    $app = $node($production, 'shop', 'app', null, null);
    $db = $node($production, 'shop-db', 'database', 'postgres', null);
    $node($staging, 'shop-staging', 'app', null, $app);
    $node($staging, 'shop-db-staging', 'database', 'postgres', $db);

    return [$workspace, $machine, $project, $production, $staging];
}

/** @param  array<string, mixed>  $job */
function wfpDeploy(Machine $machine, string $service, array $job): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $machine->workspace->owner_id,
        'kind' => 'deploy', 'service' => $service, 'state' => 'succeeded', 'command' => ['job' => $job], 'result' => [],
    ]);
}

/**
 * Run "trigger → the step → ok alert / failed alert" by hand.
 *
 * @param  array<string, mixed>  $rules
 */
function wfpRun(Workspace $workspace, string $kind, array $rules): WorkflowRun
{
    $graph = WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'rules' => ['event' => 'manual']],
        ['id' => 's', 'kind' => $kind, 'x' => 0, 'y' => 200, 'rules' => $rules],
        ['id' => 'ok', 'kind' => 'alert', 'x' => 0, 'y' => 400, 'rules' => ['subject' => 'Worked']],
        ['id' => 'bad', 'kind' => 'alert', 'x' => 200, 'y' => 400, 'rules' => ['subject' => 'Failed']],
    ], 'edges' => [
        ['id' => 'e1', 'source' => 't', 'port' => 'out', 'target' => 's'],
        ['id' => 'e2', 'source' => 's', 'port' => 'out', 'target' => 'ok'],
        ['id' => 'e3', 'source' => 's', 'port' => 'failed', 'target' => 'bad'],
    ]]);
    $workflow = $workspace->workflows()->create(['name' => 'Flow', 'graph' => $graph, 'enabled' => true, 'run_as' => $workspace->owner_id, 'trigger' => 'manual']);

    return app(WorkflowEngine::class)->start($workflow, 'manual', 'By hand', [], $workspace->owner, limited: false)->refresh();
}

/** The daemon reports a job finished. */
function wfpFinish(DaemonJob $job, string $state, array $result = []): void
{
    $job->forceFill(['state' => $state, 'result' => $result, 'finished_at' => now()])->save();
    app(ProjectData::class)->jobFinished($job->fresh());
    app(WorkflowEngine::class)->jobFinished($job->fresh());
}

test('the steps need a project in the workspace and the right environments', function () {
    [$workspace, , $project, $production, $staging] = wfpSetup();
    $other = Workspace::factory()->create()->projects()->create(['name' => 'Theirs']);

    $problems = fn (string $kind, array $rules) => WorkflowSteps::problems(WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']],
        ['id' => 's', 'kind' => $kind, 'rules' => $rules],
    ], 'edges' => []]), $workspace)['s'] ?? null;

    expect($problems('copy_data', []))->toBe('Choose a project.')
        ->and($problems('copy_data', ['project' => $other->id, 'environment' => $staging->id]))->toBe('Choose a project.')
        ->and($problems('copy_data', ['project' => $project->id]))->toBe('Choose the environment to copy data into.')
        ->and($problems('copy_data', ['project' => $project->id, 'environment' => $production->id]))->toContain('Choose a copy')
        ->and($problems('copy_data', ['project' => $project->id, 'environment' => $staging->id]))->toBeNull()
        ->and($problems('promote', ['project' => $project->id, 'from' => $staging->id, 'to' => $staging->id]))->toBe('Choose two different environments.')
        ->and($problems('promote', ['project' => $project->id, 'from' => $staging->id]))->toBe('Choose the environment it goes to.')
        ->and($problems('promote', ['project' => $project->id, 'from' => $staging->id, 'to' => $production->id]))->toBeNull();
});

test('copying data waits for the copy, with a longer timeout, and goes on when it worked', function () {
    [$workspace, , $project, , $staging] = wfpSetup();
    $run = wfpRun($workspace, 'copy_data', ['project' => $project->id, 'environment' => $staging->id]);

    $job = DaemonJob::query()->where('kind', 'service_exec')->sole();
    expect($run->steps['s']['state'])->toBe('running')
        ->and($run->steps['s']['timeout'])->toBe(WorkflowEngine::COPY_TIMEOUT_MINUTES)
        ->and($run->steps['s']['message'])->toContain('Copying 1 database from Production into Staging')
        ->and($job->only(['workflow_run_id', 'workflow_node']))->toBe(['workflow_run_id' => $run->id, 'workflow_node' => 's'])
        ->and($job->command['job']['env']['SOURCE_HOST'])->toBe('172.17.0.1');

    // Past the usual 45 minutes it's still waiting; past 70 it gives up.
    $steps = $run->steps;
    $steps['s']['startedAt'] = now()->subMinutes(50)->getTimestamp();
    $run->forceFill(['steps' => $steps, 'started_at' => now()->subMinutes(50)])->save();
    app(WorkflowEngine::class)->tick();
    expect($run->fresh()->steps['s']['state'])->toBe('running');

    wfpFinish($job, 'succeeded', ['exit_code' => 0]);
    $run->refresh();
    expect($run->steps['s']['state'])->toBe('succeeded')
        ->and($run->steps['s']['message'])->toBe('Data copied')
        ->and($run->steps)->toHaveKey('ok')
        ->and($run->steps)->not->toHaveKey('bad')
        ->and(ProjectNode::query()->where('name', 'shop-db-staging')->value('data_state'))->toBe('copied');
});

test('a copy that exits badly takes the failed path, and one that never reports times out at 70 minutes', function () {
    [$workspace, , $project, , $staging] = wfpSetup();
    $run = wfpRun($workspace, 'copy_data', ['project' => $project->id, 'environment' => $staging->id]);
    wfpFinish(DaemonJob::query()->where('kind', 'service_exec')->sole(), 'succeeded', ['exit_code' => 1]);

    $run->refresh();
    expect($run->steps['s']['state'])->toBe('failed')
        ->and($run->steps['s']['message'])->toContain('exit code 1')
        ->and($run->steps)->toHaveKey('bad');

    $again = wfpRun($workspace, 'copy_data', ['project' => $project->id, 'environment' => $staging->id]);
    $steps = $again->steps;
    $steps['s']['startedAt'] = now()->subMinutes(71)->getTimestamp();
    $again->forceFill(['steps' => $steps, 'started_at' => now()->subMinutes(71)])->save();
    app(WorkflowEngine::class)->tick();
    expect($again->fresh()->steps['s'])->toMatchArray(['state' => 'failed', 'message' => 'The machine didn’t report back within 70 minutes.']);
});

test('copying into an environment with no database to copy fails and says so', function () {
    [$workspace, , $project, , $staging] = wfpSetup();
    $staging->nodes()->where('kind', 'database')->delete();

    $run = wfpRun($workspace, 'copy_data', ['project' => $project->id, 'environment' => $staging->id]);
    expect($run->steps['s'])->toMatchArray(['state' => 'failed', 'message' => 'There’s no database in it to copy into.'])
        ->and($run->steps)->toHaveKey('bad');
});

test('promoting waits on each deploy and follows how they went', function () {
    [$workspace, , $project, $production, $staging] = wfpSetup();
    $run = wfpRun($workspace, 'promote', ['project' => $project->id, 'from' => $staging->id, 'to' => $production->id]);

    $deploy = DaemonJob::query()->where('kind', 'deploy')->where('service', 'shop')->where('state', '!=', 'succeeded')->sole();
    expect($run->steps['s']['state'])->toBe('running')
        ->and($deploy->workflow_run_id)->toBe($run->id)
        ->and($deploy->command['job']['commit'])->toBe(str_repeat('b', 40))
        ->and($deploy->command['job']['domains'])->toBe(['shop.example.com'])
        ->and($deploy->command['job']['env']['APP_ENV'])->toBe('production');

    wfpFinish($deploy, 'succeeded');
    expect($run->fresh()->steps['s'])->toMatchArray(['state' => 'succeeded', 'message' => 'Promoted']);

    // A failed health check: the live version stays, the run goes down failed.
    wfpDeploy($project->workspace->machines()->first(), 'shop-staging', ['type' => 'deploy', 'service' => 'shop-staging', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('c', 40), 'env' => [], 'domains' => [], 'port' => 3000]);
    $failing = wfpRun($workspace, 'promote', ['project' => $project->id, 'from' => $staging->id, 'to' => $production->id]);
    wfpFinish(DaemonJob::query()->where('kind', 'deploy')->where('service', 'shop')->where('workflow_run_id', $failing->id)->sole(), 'failed');
    expect($failing->fresh()->steps['s']['state'])->toBe('failed')
        ->and($failing->fresh()->steps)->toHaveKey('bad');
});

test('with nothing to promote the step goes straight on', function () {
    [$workspace, $machine, $project, $production, $staging] = wfpSetup();
    wfpDeploy($machine, 'shop-staging', ['type' => 'deploy', 'service' => 'shop-staging', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'env' => [], 'domains' => [], 'port' => 3000]);

    $run = wfpRun($workspace, 'promote', ['project' => $project->id, 'from' => $staging->id, 'to' => $production->id]);
    expect($run->steps['s'])->toMatchArray(['state' => 'succeeded', 'message' => 'Nothing to promote: every app is already at that code.'])
        ->and($run->steps)->toHaveKey('ok');
});

test('a step pointed at another workspace’s project fails without touching it', function () {
    [$workspace] = wfpSetup();
    [, , $theirs, , $theirStaging] = wfpSetup();

    $run = wfpRun($workspace, 'copy_data', ['project' => $theirs->id, 'environment' => $theirStaging->id]);
    expect($run->steps['s'])->toMatchArray(['state' => 'failed', 'message' => 'The project is gone; choose another.'])
        ->and(DaemonJob::query()->where('kind', 'service_exec')->exists())->toBeFalse();
});

test('the staging templates fill in the first project’s copy, and the editor gets projects to choose from', function () {
    [$workspace, , $project, $production, $staging] = wfpSetup();

    $refresh = WorkflowTemplates::build('staging-refresh', $workspace);
    $copy = collect($refresh['graph']['nodes'])->firstWhere('kind', 'copy_data');
    expect((array) $copy['rules'])->toMatchArray(['project' => $project->id, 'environment' => $staging->id]);

    $promote = WorkflowTemplates::build('staging-promote', $workspace);
    $step = collect($promote['graph']['nodes'])->firstWhere('kind', 'promote');
    expect((array) $step['rules'])->toMatchArray(['project' => $project->id, 'from' => $staging->id, 'to' => $production->id])
        ->and($step['title'])->toBe('Promote to Production');

    expect(WorkflowInputs::options($workspace)['projects'])->toBe([[
        'id' => $project->id, 'name' => 'Shop',
        'environments' => [['id' => $production->id, 'name' => 'Production', 'source' => null], ['id' => $staging->id, 'name' => 'Staging', 'source' => $production->id]],
    ]]);
});

test('a command that exits badly takes the failed path, and one that works goes on', function (int $code, string $port) {
    [$workspace, $machine] = wfpSetup();
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $machine->forceFill(['inventory' => ['services' => [['key' => 'docker:app1', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true']]]]])->save();

    $run = wfpRun($workspace, 'command', ['machine_id' => (string) $machine->id, 'service' => 'docker:app1', 'command' => 'php artisan migrate --force']);
    wfpFinish(DaemonJob::query()->where('kind', 'service_exec')->sole(), 'succeeded', ['exit_code' => $code]);

    $step = $run->fresh()->steps['s'];
    expect($step['port'])->toBe($port);
    if ($code !== 0) {
        expect($step['message'])->toContain("exit code {$code}");
    }
})->with(['exits 0' => [0, 'out'], 'exits 1' => [1, 'failed']]);

test('a copy already running (a nightly refresh) is waited on, not mistaken for an empty environment', function () {
    [$workspace, , $project, , $staging] = wfpSetup();
    app(ProjectData::class)->refresh($staging, $workspace->owner);
    $job = DaemonJob::query()->where('kind', 'service_exec')->sole();

    $run = wfpRun($workspace, 'copy_data', ['project' => $project->id, 'environment' => $staging->id]);
    expect($run->steps['s']['state'])->toBe('running')
        ->and($run->steps['s']['jobs'])->toBe([$job->id])
        ->and(DaemonJob::query()->where('kind', 'service_exec')->count())->toBe(1);

    wfpFinish($job, 'succeeded', ['exit_code' => 0]);
    expect($run->fresh()->steps['s']['state'])->toBe('succeeded');
});

test('a copy that couldn’t start fails the step and says why', function () {
    [$workspace, $machine, $project, , $staging] = wfpSetup();
    $machine->forceFill(['daemon_version' => '0.1.1-pre.30'])->save();

    $run = wfpRun($workspace, 'copy_data', ['project' => $project->id, 'environment' => $staging->id]);
    expect($run->steps['s']['state'])->toBe('failed')
        ->and($run->steps['s']['message'])->toContain('too old')
        ->and($run->steps)->toHaveKey('bad');
});

test('a promote that can’t deploy fails instead of saying there was nothing to promote, and unrelated environments are refused', function () {
    [$workspace, $machine, $project, $production, $staging] = wfpSetup();
    $machine->forceFill(['last_seen_at' => now()->subDay()])->save();

    $run = wfpRun($workspace, 'promote', ['project' => $project->id, 'from' => $staging->id, 'to' => $production->id]);
    expect($run->steps['s']['state'])->toBe('failed')
        ->and($run->steps['s']['message'])->toStartWith('Nothing was promoted. shop:')
        ->and($run->steps)->toHaveKey('bad');

    $qa = $project->environments()->create(['name' => 'QA', 'position' => 2, 'source_id' => $production->id]);
    expect(WorkflowSteps::problems(WorkflowSteps::clean(['nodes' => [
        ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'rules' => ['event' => 'manual']],
        ['id' => 's', 'kind' => 'promote', 'x' => 0, 'y' => 200, 'rules' => ['project' => $project->id, 'from' => $staging->id, 'to' => $qa->id]],
    ], 'edges' => [['id' => 'e', 'source' => 't', 'port' => 'out', 'target' => 's']]]), $workspace))->toHaveKey('s');
});
