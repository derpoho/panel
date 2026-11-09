<?php

namespace App\Http\Controllers;

use App\Models\AppInstall;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;
use App\Support\Projects\Placement;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The deploy dialogs: a repository built with its own Dockerfile, or a
 * service template. What they accept and send is DeployRequest's, which
 * the MCP deploy tools share.
 */
class MachineDeployController extends Controller
{
    #[Audited]
    public function __invoke(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue, ServiceTemplateCatalog $catalog): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], config('daemon.commands_enabled') ? 409 : 503);
        }

        if ($request->filled('template')) {
            return $this->deployTemplate($request, $machine, $queue, $catalog);
        }

        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => DeployRequest::bareDomain($domain)]);
        }

        $data = $request->validate(DeployRequest::gitRules());

        DeployRequest::assertPublicRepo($data['repo']);
        DeployRequest::assertWildcard($machine, $data['domain'] ?? null, (bool) ($data['wildcard'] ?? false));

        return $this->dispatch($request, $machine, $queue, DeployRequest::gitJob($data));
    }

    /**
     * The names and ports already in use on the machine, so the dialog can
     * suggest free ones instead of redeploying what's there.
     */
    public function taken(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json(Placement::taken($machine));
    }

    /**
     * Deploy from a service template: a ready-made Compose stack, or an app
     * framework building the user's repository with our Dockerfile.
     */
    private function deployTemplate(Request $request, Machine $machine, DaemonJobQueue $queue, ServiceTemplateCatalog $catalog): JsonResponse
    {
        $template = $catalog->find((string) $request->input('template'));
        if ($template === null) {
            throw ValidationException::withMessages(['template' => 'That template doesn’t exist.']);
        }

        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => DeployRequest::bareDomain($domain)]);
        }

        $data = $request->validate(DeployRequest::templateRules($template));

        if ($template->isApp()) {
            DeployRequest::assertPublicRepo($data['repo']);
        }
        DeployRequest::assertWildcard($machine, $template->web ? ($data['domain'] ?? null) : null, (bool) ($data['wildcard'] ?? false));

        $deployment = new TemplateDeployment($template, $machine, $data);
        $response = $this->dispatch($request, $machine, $queue, $deployment->job());

        if ($response->getStatusCode() !== 202) {
            return $response;
        }

        AppInstall::record($machine, $request->user(), $template->slug, $response->getData(true)['jobId'] ?? null, 'panel');

        // Generated secrets are shown once and not kept by the panel.
        return response()->json([
            ...$response->getData(true),
            ...DeployRequest::templateResult($deployment, $data),
        ], 202);
    }

    /**
     * Queue the deploy and answer the way the dialogs expect.
     *
     * @param  array<string, mixed>  $job
     */
    private function dispatch(Request $request, Machine $machine, DaemonJobQueue $queue, array $job): JsonResponse
    {
        DeployRequest::assertDocker($machine, $request->user());

        try {
            $record = $queue->deploy($machine, $request->user(), $job);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }
}
