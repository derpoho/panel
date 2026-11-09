<?php

use App\Enums\Plan;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\UpgradeLink;
use App\Support\Mcp\McpUsage;
use Illuminate\Support\Facades\DB;

function usedCalls(Workspace $workspace, int $calls): void
{
    DB::table('mcp_usage')->updateOrInsert(
        ['workspace_id' => $workspace->id, 'period' => McpUsage::period()],
        ['calls' => $calls, 'created_at' => now(), 'updated_at' => now()],
    );
}

test('each plan comes with AI tool calls, and a trial gets Pro’s', function () {
    expect(Plan::Essential->mcpCallLimit())->toBe(500)
        ->and(Plan::Pro->mcpCallLimit())->toBe(2500)
        ->and(Plan::Business->mcpCallLimit())->toBe(10000);

    expect(Workspace::factory()->create()->mcpCallLimit())->toBe(2500)
        ->and(Workspace::factory()->onPlan(Plan::Essential)->create()->mcpCallLimit())->toBe(500)
        ->and(Workspace::factory()->trialExpired()->create()->mcpCallLimit())->toBe(0);
});

test('a tool call that works counts once; failures, lists and pings don’t', function () {
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    mcpRequest($workspace, $key, 'ping')->assertOk();
    mcpRequest($workspace, $key, 'tools/list')->assertOk();
    expect(McpUsage::used($workspace))->toBe(0);

    expect(mcpCall($workspace, $key, 'get_workspace')['error'])->toBeFalse();
    expect(McpUsage::used($workspace))->toBe(1);

    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => 'nope'])['error'])->toBeTrue();
    expect(McpUsage::used($workspace))->toBe(1);

    // The call asking is counted too: it's claimed before the tool runs.
    $result = mcpCall($workspace, $key, 'get_workspace');
    expect($result['data']['workspace']['aiToolCalls'])->toMatchArray(['used' => 2, 'limit' => 2500]);
});

test('claiming a call takes one only while there are some left, and a failed call gives it back', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Essential)->create();
    usedCalls($workspace, 499);

    expect(McpUsage::claim($workspace))->toBe(McpUsage::period())
        ->and(McpUsage::claim($workspace))->toBeNull()
        ->and(McpUsage::used($workspace))->toBe(500);

    McpUsage::release($workspace, McpUsage::period());
    expect(McpUsage::used($workspace))->toBe(499);

    // A tool that fails while at the limit-but-one doesn't use it up.
    $key = mcpKey($workspace);
    expect(mcpCall($workspace, $key, 'get_machine', ['machine' => 'nope'])['error'])->toBeTrue()
        ->and(McpUsage::used($workspace))->toBe(499)
        ->and(mcpCall($workspace, $key, 'get_workspace')['error'])->toBeFalse()
        ->and(mcpCall($workspace, $key, 'get_workspace')['data']['error'] ?? '')->toContain('used all 500');
});

test('calls claimed at the same time never go past the limit', function () {
    // Separate processes on one SQLite file, each claiming as a tool call would.
    $file = tempnam(sys_get_temp_dir(), 'mcp-race-');
    $pdo = new PDO("sqlite:{$file}");
    // In WAL already, as the app's connections set it: twelve processes all
    // switching the file to it at once could find it locked.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE mcp_usage (id INTEGER PRIMARY KEY, workspace_id INTEGER, period TEXT, calls INTEGER, created_at TEXT, updated_at TEXT, UNIQUE (workspace_id, period))');
    $pdo->exec('CREATE TABLE mcp_call_packs (id INTEGER PRIMARY KEY, workspace_id INTEGER, period TEXT, calls INTEGER)');
    $pdo->prepare('INSERT INTO mcp_usage (workspace_id, period, calls) VALUES (1, ?, 495)')->execute([McpUsage::period()]);

    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.busy_timeout' => 10000]);
        $workspace = (new App\Models\Workspace)->forceFill(['id' => 1, 'plan' => App\Enums\Plan::Essential]);
        usleep(200000);
        echo App\Support\Mcp\McpUsage::claim($workspace) === null ? 'refused' : 'claimed';
        PHP;
    $processes = [];
    foreach (range(1, 12) as $i) {
        $processes[] = proc_open([PHP_BINARY, '-r', $script, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $outputs[] = $pipes[1];
    }
    $results = array_map(fn ($pipe) => stream_get_contents($pipe), $outputs);
    array_map('proc_close', $processes);

    $calls = (int) $pdo->query('SELECT calls FROM mcp_usage')->fetchColumn();
    $pdo = null;
    foreach ([$file, "{$file}-wal", "{$file}-shm"] as $path) {
        @unlink($path);
    }

    expect(array_count_values($results))->toEqual(['claimed' => 5, 'refused' => 7])
        ->and($calls)->toBe(500);
});

test('once the month’s calls are used up, tools are refused with where to get more', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Essential)->create();
    $key = mcpKey($workspace);
    usedCalls($workspace, 500);
    // Where to get more is the upgrade link a package gives.
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));

    $result = mcpCall($workspace, $key, 'get_workspace');
    expect($result['error'])->toBeTrue()
        ->and($result['data']['error'])->toContain('used all 500 of its AI tool calls')
        ->and($result['data']['error'])->toContain('in Billing: https://'.$workspace->host().'/settings/general');
    expect(McpUsage::used($workspace))->toBe(500);

    // Connecting still works, so the app can say why.
    mcpRequest($workspace, $key, 'tools/list')->assertOk()->assertJsonStructure(['result' => ['tools']]);

    // Next month starts again.
    $this->travelTo(now()->startOfMonth()->addMonth()->addHour());
    expect(mcpCall($workspace, $key, 'get_workspace')['error'])->toBeFalse();
});

test('a workspace with no plan can’t use AI tools', function () {
    $workspace = Workspace::factory()->trialExpired()->create();
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'get_workspace');
    expect($result['error'])->toBeTrue()
        ->and($result['data']['error'])->toContain('has no plan');
});
