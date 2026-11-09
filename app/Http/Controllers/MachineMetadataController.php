<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Machines\MachineMetadata;
use App\Support\Machines\MachinePolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Setting details on many machines at once from Machines: the
 * provider, region and tier, and labels to add or take off. What's left
 * out stays as each machine has it.
 */
class MachineMetadataController extends Controller
{
    #[Audited]
    public function bulk(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'machines' => ['required', 'array', 'min:1', 'max:500'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'provider' => ['sometimes', 'nullable', 'string', 'max:60'],
            'region' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
            // Set these labels (key => value) on every machine...
            'labels' => ['sometimes', 'array', 'max:'.MachineMetadata::MAX_LABELS],
            'labels.*.key' => ['required', 'string'],
            'labels.*.value' => ['required', 'string'],
            // ...and take these off.
            'remove_labels' => ['sometimes', 'array', 'max:'.MachineMetadata::MAX_LABELS],
            'remove_labels.*' => ['string'],
        ]);

        $changes = array_intersect_key($data, array_flip(['provider', 'region', 'tier']));
        /** @var list<array{key: string, value: string}> $set validated above */
        $set = $data['labels'] ?? [];
        /** @var list<string> $remove validated above */
        $remove = $data['remove_labels'] ?? [];
        $labels = collect($set)->mapWithKeys(fn (array $label) => [trim($label['key']) => $label['value']])
            ->merge(collect($remove)->mapWithKeys(fn (string $key) => [trim($key) => null]))->all();
        if ($labels !== []) {
            $changes['labels'] = $labels;
        }
        $ids = array_values(array_unique(array_map('intval', $data['machines'])));

        $changed = MachinePolicies::whileChanging($workspace, $ids, fn () => DB::transaction(function () use ($workspace, $ids, $changes) {
            $changed = 0;
            $fields = [];
            foreach ($workspace->machines()->whereIn('id', $ids)->get() as $machine) {
                /** @var Machine $machine */
                $machineFields = MachineMetadata::apply($machine, $changes);
                if ($machineFields !== []) {
                    $machine->save();
                    $changed++;
                    $fields = [...$fields, ...$machineFields];
                }
            }

            return ['machines' => $changed, 'fields' => array_values(array_unique($fields))];
        }));

        if ($changed['machines'] === 0) {
            AuditContext::nothingChanged();
        } else {
            Activity::record($workspace, 'machines.metadata', null, meta: $changed);
        }

        return back();
    }
}
