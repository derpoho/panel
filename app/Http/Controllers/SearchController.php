<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\StatusPages\StatusReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What ⌘K finds: machines, services, monitors and workflows whose name
 * (or address) matches, a few of each.
 */
class SearchController extends Controller
{
    public const EACH = 5;

    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->validate(['q' => ['required', 'string', 'max:100']])['q']));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $machines = $workspace->machines()->orderBy('name')->get(['id', 'name', 'ip_address', 'inventory', 'service_labels']);

        $services = [];
        foreach ($machines as $machine) {
            foreach (AppReleases::visible($machine) as $service) {
                $name = (string) ($service['name'] ?? '');
                $label = $service['label'] ?? StatusReport::friendlyName($service['app'] ?? $name);
                if ($name !== '' && (str_contains(mb_strtolower($name), $q) || str_contains(mb_strtolower($label), $q))) {
                    $services[] = ['label' => $label, 'hint' => "{$name} · {$machine->name}", 'url' => "/machines/{$machine->id}/services/".(isset($service['app']) ? "app:{$service['app']}" : $service['key'])];
                }
                if (count($services) >= self::EACH) {
                    break 2;
                }
            }
        }

        return response()->json([
            'machines' => $machines
                ->filter(fn (Machine $m) => str_contains(mb_strtolower($m->name), $q) || str_contains((string) $m->ip_address, $q))
                ->take(self::EACH)
                ->map(fn (Machine $m) => ['label' => $m->name, 'hint' => $m->ip_address, 'url' => "/machines/{$m->id}"])
                ->values(),
            'services' => $services,
            'monitors' => $workspace->monitors()
                ->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('url', $like)->orWhereLike('host', $like))
                ->orderBy('name')->limit(self::EACH)->get()
                ->map(fn ($m) => ['label' => $m->name, 'hint' => $m->target(), 'url' => "/monitors/{$m->id}"]),
            'workflows' => $workspace->workflows()->whereLike('name', $like)->orderBy('name')->limit(self::EACH)->get(['id', 'name', 'enabled'])
                ->map(fn ($w) => ['label' => $w->name, 'hint' => $w->enabled ? 'On' : 'Off', 'url' => "/workflows/{$w->id}"]),
        ]);
    }
}
