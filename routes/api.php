<?php

use App\Api\ApiRoutes;
use App\Http\Controllers\Api\DaemonEnrolController;
use App\Http\Controllers\Api\DaemonGatewayController;
use App\Http\Controllers\Api\DaemonReleaseController;
use App\Http\Controllers\Api\GitSourceController;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkspaceDomainController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Support\Extensions\Extensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('daemon/enrol', DaemonEnrolController::class)->middleware('throttle:20,1');
// The daemon's update feed: daemons read the latest release, CI registers new ones.
Route::get('daemon/releases/latest', [DaemonReleaseController::class, 'latest'])->middleware('throttle:60,1');
Route::post('daemon/releases', [DaemonReleaseController::class, 'store'])->middleware('throttle:20,1');
Route::put('daemon/releases/{version}/{file}', [DaemonReleaseController::class, 'upload'])->middleware('throttle:20,1');
Route::post('gateway/hello', [DaemonGatewayController::class, 'hello'])->middleware('throttle:300,1');
Route::post('gateway/machines/{uid}/ingest', [DaemonGatewayController::class, 'ingest'])->middleware('throttle:600,1');
Route::post('gateway/machines/{uid}/closed', [DaemonGatewayController::class, 'closed'])->middleware('throttle:300,1');
// Caddy's on-demand TLS asks here before getting a certificate (loopback only).
Route::get('internal/tls-check', [WorkspaceDomainController::class, 'tlsCheck'])->name('domains.tls-check');
Route::post('github/webhook', GitHubWebhookController::class)->middleware('throttle:300,1')->name('github.webhook');
Route::post('hooks/workflows/{token}', [WorkflowController::class, 'hook'])->middleware('throttle:60,1')->where('token', 'wh_[A-Za-z0-9]{40}')->name('workflows.hook');

// Uploaded projects, served read-only to the daemon as Git repositories.
Route::match(['get', 'post'], 'deploy-sources/{token}.git/{path}', GitSourceController::class)
    ->where(['token' => '[A-Za-z0-9]{48}', 'path' => 'info/refs|git-upload-pack'])
    ->middleware('throttle:120,1')
    ->name('deploy-sources.git');

// The REST API (https://serveros.com/api/v1): every route runs one of the
// MCP server's tools, with a workspace API key. See App\Api\ApiRoutes.
Route::prefix('v1')->group(function () {
    Route::get('openapi.json', [ApiController::class, 'openapi'])->name('api.v1.openapi');

    Route::middleware(AuthenticateApiKey::class)->group(function () {
        Route::get('/', [ApiController::class, 'index'])->name('api.v1.index');
        Route::get('tools', [ApiController::class, 'tools'])->name('api.v1.tools');
        Route::post('tools/{name}', [ApiController::class, 'tool'])->where('name', '[a-z_]{1,64}')->name('api.v1.tool');

        foreach (ApiRoutes::all() as $route) {
            // A closure, not [ApiController::class, 'route']: one controller
            // method behind 113 routes would give Wayfinder 113 helpers of one name.
            Route::match([$route['method']], $route['path'], fn (Request $request) => app(ApiController::class)->route($request))
                ->defaults('tool', $route['tool']);
        }
    });
});

// API routes from the packages.
app(Extensions::class)->loadRoutes(Extensions::API);
