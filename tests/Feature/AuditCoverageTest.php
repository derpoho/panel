<?php

use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolRegistry;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditCoverage;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/** @return list<RoutingRoute> */
function auditedScope(): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $route) => AuditCoverage::inScope($route)));
}

test('every write route in a workspace, account settings and admin says whether it is audited', function () {
    $missing = collect(auditedScope())
        ->filter(fn (RoutingRoute $route) => AuditCoverage::declaration($route) === null)
        ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri().' → '.$route->getActionName())
        ->values()
        ->all();

    expect($missing)->toBe([], "Mark each with #[Audited] (it records to Activity or the admin log) or #[NotAudited('why')]:\n".implode("\n", $missing));
});

test('the scope covers the areas it should', function () {
    $names = collect(auditedScope())->map(fn (RoutingRoute $route) => $route->getName())->filter();

    expect($names)->toContain('workspace.machines.services.action', 'workspace.settings.update', 'impersonation.stop', 'profile.update', 'invitations.accept', 'mcp.oauth.approve')
        ->not->toContain('github.webhook', 'mcp.oauth.register');
});

test('every reason given for not auditing says something', function () {
    $blank = collect(auditedScope())
        ->map(fn (RoutingRoute $route) => [$route, AuditCoverage::declaration($route)])
        ->filter(fn (array $pair) => $pair[1] instanceof NotAudited && trim($pair[1]->why) === '')
        ->map(fn (array $pair) => $pair[0]->uri());

    expect($blank->all())->toBe([]);
});

test('the not audited list stays short', function () {
    $declared = collect(auditedScope())->mapWithKeys(fn (RoutingRoute $route) => [$route->getActionName() => AuditCoverage::declaration($route)]);
    $skipped = $declared->filter(fn ($d, string $action) => $d instanceof NotAudited && str_starts_with($action, 'App\\'));

    expect($declared->filter(fn ($d) => $d instanceof Audited)->count())->toBeGreaterThan($declared->filter(fn ($d) => $d instanceof NotAudited)->count())
        ->and($skipped->count())->toBeLessThanOrEqual(30, 'A new #[NotAudited] write? Record it instead if you can; if not, raise this on purpose.');
});

test('every MCP tool that can change things records it, unless it says why not', function () {
    $tools = collect(app(ToolRegistry::class)->all());
    $writes = $tools->filter(fn (Tool $tool) => $tool->scope() !== Scopes::READ);
    $skipped = $writes->filter(fn (Tool $tool) => AuditCoverage::toolDeclaration($tool) instanceof NotAudited);

    expect($writes)->not->toBeEmpty()
        ->and($tools->filter(fn (Tool $tool) => $tool->scope() === Scopes::READ)->map(fn (Tool $tool) => AuditCoverage::toolDeclaration($tool))->filter()->all())->toBe([])
        ->and($skipped->filter(fn (Tool $tool) => trim(AuditCoverage::toolDeclaration($tool)->why) === '')->all())->toBe([])
        ->and($skipped->count())->toBeLessThan(intdiv($writes->count(), 4));
});

test('an MCP tool that changes something without recording it is caught', function () {
    $tool = app(ToolRegistry::class)->find('pause_monitor');
    $mcp = fn (): Request => tap(Request::create('/mcp', 'POST'), function (Request $request): void {
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName('mcp'));
        app()->instance('request', $request);
    });
    $request = $mcp();

    AuditCoverage::afterTool($tool);
    expect(AuditCoverage::missedToolRecordings($request))->toHaveCount(1)
        ->and(AuditCoverage::missedToolRecordings($request)[0])->toContain('pause_monitor');

    $quiet = $mcp();
    AuditContext::nothingChanged();
    AuditCoverage::afterTool($tool);
    AuditCoverage::afterTool(app(ToolRegistry::class)->find('send_test_alert'));
    expect(AuditCoverage::missedToolRecordings($quiet))->toBe([]);
});

test('an audited write that succeeds without recording anything is caught', function () {
    $route = Route::getRoutes()->getByName('workspace.settings.update');
    $request = Request::create('/settings/general', 'PATCH');
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    expect(AuditCoverage::missedRecording($request, new RedirectResponse('/back')))->toContain('is #[Audited] but recorded nothing')
        ->and(AuditCoverage::missedRecording($request, new JsonResponse([], 422)))->toBeNull();

    AuditContext::nothingChanged();
    expect(AuditCoverage::missedRecording($request, new RedirectResponse('/back')))->toBeNull();
});
