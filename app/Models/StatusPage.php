<?php

namespace App\Models;

use App\Models\Concerns\HasStatusBranding;
use App\Support\Machines\MachinePolicies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A machine's status page: its services and whether they're up, at
 * /status/{slug}, public or for the workspace only. A Pro feature.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $slug
 * @property bool $enabled
 * @property bool $public
 * @property string|null $title
 * @property string|null $description
 * @property string|null $page_title the browser tab's, when not the name
 * @property string|null $icon_path beside the name, else the workspace logo
 * @property string|null $favicon_path
 * @property array<string, array{shown?: bool, label?: string|null, quiet?: bool, section?: string}>|null $services
 * @property bool $include_new
 * @property bool $show_uptime
 * @property string|null $notice
 * @property string|null $notice_kind info, maintenance or incident
 * @property array<string, array{batches: int, services: array<string, array{0: int, 1: int}>, last?: list<string>, grace?: list<string>}>|null $uptime
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 */
class StatusPage extends Model
{
    use HasStatusBranding;

    public const NOTICE_KINDS = ['info', 'maintenance', 'incident'];

    /** Days of uptime kept and shown. */
    public const UPTIME_DAYS = 90;

    protected $guarded = ['id', 'machine_id', 'uptime'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'public' => 'boolean',
            'include_new' => 'boolean',
            'show_uptime' => 'boolean',
            'services' => 'array',
            'uptime' => 'array',
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

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * A new page for the machine, off, at a free address made from its name.
     * Not saved.
     */
    public static function draftFor(Machine $machine): self
    {
        $base = Str::slug($machine->name) ?: 'status';
        $slug = $base;
        while (self::query()->where('slug', $slug)->exists() || WorkspaceStatusPage::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        // Starts empty, so nothing goes public until it's picked, unless the
        // machine's policy says to show services as they're found.
        $includeNew = MachinePolicies::for($machine)->status_page_new;
        $page = new self(['slug' => $slug, 'enabled' => false, 'public' => true, 'include_new' => $includeNew, 'show_uptime' => true]);
        $page->machine_id = $machine->id;
        $page->setRelation('machine', $machine);

        return $page;
    }

    /**
     * Where the machine's uptime is counted: its own page when that's live,
     * or when the workspace's fleet page shows it (a page that's off is
     * made for that, so the history is there if it's turned on later).
     */
    public static function recordingFor(Machine $machine): ?self
    {
        $page = $machine->statusPage;
        if ($page?->live()) {
            return $page;
        }

        $fleet = $machine->workspace->fleetStatusPage;
        if ($fleet === null || ! $fleet->live() || ! $fleet->covers($machine)) {
            return null;
        }

        if ($page === null) {
            $page = self::draftFor($machine);
            $page->save();
            $machine->setRelation('statusPage', $page);
        }

        return $page;
    }

    /**
     * Whether it's served right now: turned on, on a plan that has it.
     */
    public function live(): bool
    {
        return $this->enabled && $this->machine->workspace->hasStatusPages();
    }

    /**
     * Count one telemetry snapshot towards today's uptime: the machine
     * reported, and these services were running.
     *
     * A service that was running and is missing from one snapshot is given
     * the benefit of the doubt: a planned restart (a queue worker's hourly
     * --max-time, systemd's Restart=) is gone for seconds, and a snapshot can
     * land in that gap. If it's still missing in the next one, that credit
     * is taken back and both count as down.
     *
     * @param  list<string>  $running
     */
    public function recordSnapshot(array $running, ?CarbonInterface $at = null): void
    {
        $day = ($at ?? now())->toDateString();
        $uptime = $this->uptime ?? [];
        $today = $uptime[$day] ?? ['batches' => 0, 'services' => []];
        // What ran last time, carried over midnight from the day before.
        $before = $today['last'] ?? (end($uptime) !== false && key($uptime) < $day ? (end($uptime)['last'] ?? []) : []);
        $credited = $today['grace'] ?? [];
        $today['batches']++;

        // Missing again: last snapshot's benefit of the doubt was wrong.
        foreach (array_diff($credited, $running) as $key) {
            if (isset($today['services'][$key]) && $today['services'][$key][0] > 0) {
                $today['services'][$key][0]--;
            }
        }

        // Running, or missing for the first time after running.
        $grace = array_values(array_diff($before, $running, $credited));
        foreach ([...$running, ...$grace] as $key) {
            // [times seen, the batch it was first seen in]
            [$seen, $first] = $today['services'][$key] ?? [0, $today['batches']];
            $today['services'][$key] = [$seen + 1, $first];
        }

        $today['last'] = $running;
        $today['grace'] = $grace;
        $uptime[$day] = $today;
        ksort($uptime);

        $this->uptime = array_slice($uptime, -self::UPTIME_DAYS, null, true);
        $this->saveQuietly();
    }
}
