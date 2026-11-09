<?php

namespace App\Support\StatusPages;

use App\Models\Machine;
use App\Models\StatusIncident;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Posting incidents on status pages and updating them, the same from the
 * dashboard and from MCP, and emailing the pages' subscribers. The callers
 * record it in the activity log.
 */
final class StatusIncidents
{
    /** @return array<string, mixed> */
    public static function openRules(Workspace $workspace): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'impact' => ['required', Rule::in(StatusIncident::IMPACTS)],
            'state' => ['required', Rule::in(array_diff(StatusIncident::STATES, ['resolved']))],
            'message' => ['required', 'string', 'max:5000'],
            'machine_id' => ['nullable', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
        ];
    }

    /** @return array<string, mixed> */
    public static function updateRules(): array
    {
        return [
            'state' => ['required', Rule::in(StatusIncident::STATES)],
            'message' => ['required', 'string', 'max:5000'],
            'impact' => ['nullable', Rule::in(StatusIncident::IMPACTS)],
        ];
    }

    public static function open(Workspace $workspace, ?Machine $machine, ?User $user, string $title, string $impact, string $state, string $message): StatusIncident
    {
        $incident = DB::transaction(function () use ($workspace, $machine, $user, $title, $impact, $state, $message) {
            $incident = $workspace->statusIncidents()->create([
                'machine_id' => $machine?->id,
                'title' => trim($title),
                'impact' => $impact,
                'state' => $state,
                'user_id' => $user?->id,
                'started_at' => now(),
            ]);
            $incident->updates()->create(['state' => $state, 'message' => trim($message), 'user_id' => $user?->id]);

            return $incident;
        });
        StatusSubscriptions::incident($incident);

        return $incident;
    }

    /** A new update. Resolving closes it; any other state opens it again. */
    public static function update(StatusIncident $incident, ?User $user, string $state, string $message, ?string $impact = null): StatusIncident
    {
        $incident = DB::transaction(function () use ($incident, $user, $state, $message, $impact) {
            $incident->updates()->create(['state' => $state, 'message' => trim($message), 'user_id' => $user?->id]);
            $incident->fill([
                'state' => $state,
                'impact' => $impact ?? $incident->impact,
                'resolved_at' => $state === 'resolved' ? ($incident->resolved_at ?? now()) : null,
            ])->save();

            return $incident->refresh();
        });
        StatusSubscriptions::incident($incident);

        return $incident;
    }

    /**
     * Its activity action, from the state it was just given.
     */
    public static function action(string $state, bool $opened = false): string
    {
        return match (true) {
            $opened => 'status_page.incident_open',
            $state === 'resolved' => 'status_page.incident_resolve',
            default => 'status_page.incident_update',
        };
    }
}
