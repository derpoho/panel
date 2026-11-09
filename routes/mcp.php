<?php

use App\Http\Controllers\Mcp\DocsMcpController;
use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\OAuthMetadataController;
use App\Http\Controllers\Mcp\OAuthRegistrationController;
use App\Http\Controllers\Mcp\OAuthTokenController;
use App\Http\Controllers\Mcp\ServerCardController;
use App\Http\Middleware\McpCors;
use App\Support\Extensions\Extensions;
use Illuminate\Support\Facades\Route;

/*
 * MCP: each workspace's server on its own domain, and the OAuth
 * authorization server on the main one. No sessions or cookies here:
 * everything is bearer tokens and PKCE. The consent page, which needs the
 * person signed in, is in web.php.
 */
Route::middleware(McpCors::class)->group(function () {
    // The docs, for any AI app: no sign-in, read only. Before the
    // workspaces, so "docs" never reaches them.
    Route::domain('docs.'.config('app.domain'))->group(function () {
        Route::post('mcp', DocsMcpController::class)->name('docs.mcp');
        // One route for both, so the generated route helpers don't list it twice.
        Route::match(['get', 'delete'], 'mcp', [DocsMcpController::class, 'stream']);
        Route::options('mcp', fn () => response('', 204));
        Route::get('.well-known/mcp/server-card.json', [ServerCardController::class, 'docs'])->name('docs.mcp.server-card');
    });

    Route::domain('{workspace:slug}.'.config('app.domain'))->group(function () {
        Route::post('mcp', McpController::class)->name('mcp');
        Route::get('mcp', [McpController::class, 'stream']);
        Route::delete('mcp', [McpController::class, 'close']);
        Route::options('mcp', fn () => response('', 204));
        Route::get('.well-known/oauth-protected-resource/mcp', [OAuthMetadataController::class, 'resource'])->name('mcp.resource-metadata');
        Route::get('.well-known/oauth-protected-resource', [OAuthMetadataController::class, 'resource']);
    });

    // The packages' MCP servers' OAuth metadata (Extensions::MCP).
    app(Extensions::class)->loadRoutes(Extensions::MCP);

    // What our MCP server is and every tool it has, for agents to find it.
    Route::get('.well-known/mcp/server-card.json', [ServerCardController::class, 'workspace'])->name('mcp.server-card');

    Route::get('.well-known/oauth-authorization-server', [OAuthMetadataController::class, 'server'])->name('mcp.oauth.metadata');
    Route::get('.well-known/openid-configuration', [OAuthMetadataController::class, 'server']);
    Route::post('oauth/register', OAuthRegistrationController::class)->middleware('throttle:20,1')->name('mcp.oauth.register');
    Route::post('oauth/token', [OAuthTokenController::class, 'token'])->middleware('throttle:60,1')->name('mcp.oauth.token');
    Route::post('oauth/revoke', [OAuthTokenController::class, 'revoke'])->middleware('throttle:60,1')->name('mcp.oauth.revoke');
    Route::options('oauth/{endpoint}', fn () => response('', 204))->where('endpoint', 'register|token|revoke');
    Route::options('.well-known/{document}', fn () => response('', 204))->where('document', '.*');
});
