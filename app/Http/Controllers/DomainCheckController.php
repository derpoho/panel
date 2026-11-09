<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\Deploys\DeployRequest;
use App\Support\Domains\DomainCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DomainCheckController extends Controller
{
    /**
     * Before deploying behind a domain: does its DNS point at this machine?
     * Once it does, the machine's Caddy gets the certificate on its own.
     */
    #[NotAudited('A check; nothing changes')]
    public function __invoke(Request $request, Workspace $workspace, Machine $machine, DomainCheck $check): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
        ]);

        $result = $check->check($machine, $data['domain']);
        if ($request->boolean('wildcard')) {
            $result['wildcard'] = $check->checkWildcard($machine, $data['domain']);
        }

        return response()->json($result);
    }
}
