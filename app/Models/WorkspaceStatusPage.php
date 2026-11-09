<?php

namespace App\Models;

use App\Models\Concerns\HasStatusBranding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One status page for a whole workspace (Business): every machine it
 * covers as a section, each showing what that machine's own status page
 * settings pick. Served at /status/{slug} like machine pages.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $slug
 * @property bool $enabled
 * @property bool $public
 * @property string|null $title
 * @property string|null $description
 * @property string|null $page_title the browser tab's, when not the name
 * @property string|null $icon_path beside the name, else the workspace logo
 * @property string|null $favicon_path
 * @property string|null $notice
 * @property string|null $notice_kind
 * @property bool $show_uptime
 * @property list<int>|null $machines ids shown, or null for all
 * @property list<int>|null $monitors uptime monitors on no machine that it shows, by id
 * @property list<int>|null $machine_groups groups whose machines it also shows, by id
 * @property array{sections?: list<array{name: string, components: list<string>}>, headings?: array<int|string, string>, order?: list<int>}|null $layout its own sections, machine headings and machine order (WorkspaceStatusPageController::cleanLayout)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class WorkspaceStatusPage extends Model
{
    use HasStatusBranding;

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'public' => 'boolean',
            'show_uptime' => 'boolean',
            'machines' => 'array',
            'monitors' => 'array',
            'machine_groups' => 'array',
            'layout' => 'array',
        ];
    }

    /**
     * People who asked to be emailed about it.
     *
     * @return MorphMany<StatusSubscriber, $this>
     */
    public function subscribers(): MorphMany
    {
        return $this->morphMany(StatusSubscriber::class, 'subscribable');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Served right now: turned on, on a plan that has it. */
    public function live(): bool
    {
        return $this->enabled && $this->workspace->hasFleetStatusPage();
    }

    public function covers(Machine $machine): bool
    {
        return $machine->workspace_id === $this->workspace_id
            && ($this->machines === null
                || in_array($machine->id, $this->machines, true)
                || ($machine->machine_group_id !== null && in_array($machine->machine_group_id, $this->machine_groups ?? [], true)));
    }

    /**
     * The uptime monitors on no machine that it shows (a site hosted
     * elsewhere), paused ones left out, by name.
     *
     * @return Collection<int, Monitor>
     */
    public function standaloneMonitors(): Collection
    {
        if ($this->monitors === null || $this->monitors === []) {
            return new Collection;
        }

        return Monitor::query()
            ->where('workspace_id', $this->workspace_id)
            ->whereNull('machine_id')
            ->where('paused', false)
            ->whereIn('id', $this->monitors)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Machine> the machines it shows, by name */
    public function coveredMachines(): Collection
    {
        return $this->workspace->machines()
            ->whereNotNull('uid')
            // Picked machines, and every machine in the picked groups (so one
            // added to a group joins the page on its own).
            ->when($this->machines !== null, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', $this->machines)
                ->orWhereIn('machine_group_id', $this->machine_groups ?? [])))
            ->with(['statusPage', 'serviceGroups', 'workspace'])
            ->orderBy('name')
            ->get();
    }
}
