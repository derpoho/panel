<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every service on every machine in the workspace, from each machine's
 * latest discovery scan, with its live usage.
 */
class ServiceController extends Controller
{
    public function index(Workspace $workspace, ServiceTemplateCatalog $templates): Response
    {
        $machines = $workspace->machines()->orderBy('name')->get();

        return Inertia::render('workspace/Services', [
            'workspace' => $workspace->toPageProps(),
            'services' => $machines->flatMap(fn (Machine $machine) => collect(AppReleases::visible($machine))
                ->filter(fn (array $service) => isset($service['key']))
                ->map(fn (array $service) => [
                    ...$service,
                    'machine' => ['id' => $machine->id, 'name' => $machine->name],
                    'stat' => $machine->service_stats[$service['key']] ?? null,
                ]))->values(),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'connection' => MachineResource::connection($machine),
                'scanned' => $machine->inventory !== null,
            ]),
            // Not part of the list's refresh every 15 seconds.
            'templates' => fn () => $templates->toPageProps(),
            'deployEnabled' => (bool) config('daemon.commands_enabled') && filled(config('daemon.gateway_internal_url')),
        ]);
    }
}
