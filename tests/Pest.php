<?php

use App\Mcp\Scopes;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Admin\Impersonation;
use App\Support\Logs\LogStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../vendor/serveroshq/serveros.com/tests/Feature', '../vendor/serveroshq/panel-ee/tests/Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
| MCP: an API key for a workspace, and calls to its MCP server.
*/

/**
 * @param  list<string>  $scopes
 */
function mcpKey(Workspace $workspace, ?User $user = null, array $scopes = Scopes::ALL): string
{
    return McpToken::issueKey($workspace, $user ?? $workspace->owner, 'Test key', $scopes)[1];
}

/**
 * Send one JSON-RPC request to the workspace's MCP server.
 *
 * @param  array<string, mixed>  $params
 */
function mcpRequest(Workspace $workspace, string $key, string $method, array $params = [], int|string $id = 1): TestResponse
{
    return test()->withHeaders(['Authorization' => "Bearer {$key}"])
        ->postJson('https://'.$workspace->host().'/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
}

/**
 * Call a tool and return its result: ['data' => structured content, 'error' => bool, 'text' => the text].
 *
 * @param  array<string, mixed>  $arguments
 * @return array{data: mixed, error: bool, text: string}
 */
function mcpCall(Workspace $workspace, string $key, string $tool, array $arguments = []): array
{
    $response = mcpRequest($workspace, $key, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk();
    $result = $response->json('result');
    if ($result === null) {
        throw new RuntimeException('JSON-RPC error: '.json_encode($response->json('error')));
    }

    return [
        'data' => $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? 'null', true),
        'error' => (bool) ($result['isError'] ?? false),
        'text' => $result['content'][0]['text'] ?? '',
    ];
}

/**
 * A workspace's own log store, for tests: a throwaway SQLite file standing
 * in for their Postgres, enabled and with our tables in it.
 */
function ownLogStore(Workspace $workspace, array $attributes = []): WorkspaceLogStore
{
    $path = tempnam(sys_get_temp_dir(), 'serveros-logstore-');
    $store = WorkspaceLogStore::query()->forceCreate([
        'workspace_id' => $workspace->id, 'driver' => 'sqlite', 'host' => 'db.example.com', 'port' => 5432,
        'database' => $path, 'username' => 'serveros', 'password' => 'secret', 'enabled' => true, ...$attributes,
    ]);
    LogStore::install($store);
    LogStore::flush();

    return $store;
}

/*
| View as: staff signed in as someone else (App\Support\Admin\Impersonation).
*/

/**
 * Where to post ['reason' => ...] to start viewing as $user, as the signed-in
 * admin. The staff admin's button that does it lives in a package, so the
 * panel's tests start it from a route of their own, through the same
 * Impersonation::start.
 */
function viewAsUrl(User $user): string
{
    if (! Route::has('test.view-as')) {
        Route::post('test/view-as/{user}', function (Request $request, User $user) {
            $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);
            Impersonation::start($request, $request->user(), $user, $reason);

            return Inertia::location(route('dashboard'));
        })->middleware(['web', 'auth', 'verified', 'can:viewAdmin'])->name('test.view-as');
        app('router')->getRoutes()->refreshNameLookups();
    }

    return '/test/view-as/'.$user->id;
}
