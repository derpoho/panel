<?php

use App\Enums\Plan;
use App\Jobs\DiagnoseFix;
use App\Models\AgentFix;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Fixes\FixDiagnoser;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.groq.key', 'gsk_ours');
    $this->withoutDefer();
});

function aiUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().$path;
}

test('a key is checked with its provider, kept encrypted, and never sent back', function () {
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => []])]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(aiUrl($workspace, '/settings/ai/anthropic'), ['key' => 'sk-ant-api03-'.str_repeat('a', 30).'WXYZ', 'model' => 'claude-opus-5-5'])
        ->assertSessionHasNoErrors();

    $key = WorkspaceAiKey::query()->sole();
    expect($key->key)->toBe('sk-ant-api03-'.str_repeat('a', 30).'WXYZ')
        ->and($key->hint)->toBe('…WXYZ')
        ->and($key->model)->toBe('claude-opus-5-5')
        // Encrypted at rest.
        ->and(DB::table('workspace_ai_keys')->value('key'))->not->toContain('sk-ant');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com/v1/models') && $r->header('x-api-key')[0] === $key->key);

    $this->actingAs($workspace->owner)->get(aiUrl($workspace, '/settings/mcp'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.providers.0.connected', true)
            ->where('ai.providers.0.hint', '…WXYZ')
            ->where('ai.agentUses', 'Anthropic · claude-opus-5-5')
            ->missing('ai.providers.0.key'));
    $this->actingAs($workspace->owner)->get(aiUrl($workspace, '/settings/mcp'))->assertDontSee('aaaaWXYZ', false);
});

test('a key the provider refuses isn’t kept; only people who manage settings can connect one', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(aiUrl($workspace, '/settings/ai/openai'), ['key' => 'sk-proj-'.str_repeat('b', 40)])
        ->assertSessionHasErrors(['key' => 'OpenAI didn’t accept that key.']);
    expect(WorkspaceAiKey::query()->count())->toBe(0);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->put(aiUrl($workspace, '/settings/ai/openai'), ['key' => 'sk-proj-'.str_repeat('b', 40)])->assertForbidden();
    $this->actingAs($viewer)->delete(aiUrl($workspace, '/settings/ai/openai'))->assertForbidden();
});

test('with its own Anthropic key, server fixes run on it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'key' => 'sk-ant-theirs', 'hint' => '…eirs']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        ['key' => 'docker:shop', 'name' => 'shop', 'status' => 'exited', 'details' => ['managed' => 'true']],
    ]]])->save();
    // Anthropic has no JSON mode; the answer can come wrapped in words.
    Http::fake(['api.anthropic.com/v1/messages' => Http::response([
        'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => "Here it is:\n".json_encode(['title' => 'shop stopped', 'cause' => 'It exited.', 'explanation' => 'It exited.', 'confidence' => 'medium', 'steps' => [['type' => 'start', 'service' => 'docker:shop', 'why' => 'Bring it back.']]])]],
    ])]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'service_key' => 'docker:shop', 'service_name' => 'shop', 'state' => 'investigating']);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));

    expect($fix->refresh()->only(['state', 'title', 'model']))->toBe(['state' => 'needs_approval', 'title' => 'shop stopped', 'model' => 'claude-sonnet-5-5']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r->header('x-api-key')[0] === 'sk-ant-theirs' && $r['model'] === 'claude-sonnet-5-5');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
});

test('a refused key says where to fix it', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-revoked', 'hint' => '…oked']);
    $machine = Machine::factory()->for($workspace)->create();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'revoked']], 401)]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'investigating']);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));

    expect($fix->refresh()->only(['state', 'error']))->toBe(['state' => 'error', 'error' => 'Your OpenAI key was refused. Check it in Settings › AI and MCP.']);
});

test('coding agents get the workspace’s key for their agent, by name', function () {
    Http::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'key' => 'sk-ant-theirs', 'hint' => '…eirs']);
    $graph = WorkflowSteps::clean(['nodes' => [['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']]], 'edges' => []]);
    $workflow = $workspace->workflows()->create(['name' => 'W', 'graph' => $graph, 'enabled' => false, 'run_as' => $workspace->owner_id, 'trigger' => 'manual']);
    $run = $workflow->runs()->create(['state' => 'running', 'trigger' => ['kind' => 'manual', 'summary' => 'x', 'data' => []], 'graph' => $graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()]);

    $claude = AgentSpec::job($workspace, $run, 'a', 'docker:agent', ['agent' => 'claude', 'prompt' => 'Hi']);
    $codex = AgentSpec::job($workspace, $run, 'a', 'docker:agent', ['agent' => 'codex', 'prompt' => 'Hi']);

    expect($claude['env']['ANTHROPIC_API_KEY'])->toBe('sk-ant-theirs')
        ->and($claude['command'])->not->toContain('sk-ant')
        // No OpenAI key connected: Codex uses the container's own.
        ->and($codex['env'])->not->toHaveKey('OPENAI_API_KEY');
});
