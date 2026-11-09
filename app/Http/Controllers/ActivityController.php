<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityGroups;
use App\Support\Activity\ActivityLine;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Device;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who did what in the workspace, and what happened on its own, newest
 * first: the first place to look when something changed and nobody knows
 * why. Filters by person (members, people who've left, ServerOS Support or
 * nobody), kind of action, machine and time; the export takes the same.
 */
class ActivityController extends Controller
{
    public const PAGE = 50;

    /** The date presets, in days back from today (today counts). */
    public const WHEN = ['today' => 1, '7d' => 7, '30d' => 30];

    public function index(Request $request, Workspace $workspace): Response
    {
        $machines = $workspace->machines()->orderBy('name')->get(['id', 'name']);
        $people = self::people($workspace);
        $filters = self::filters($request, $workspace, $people);

        $entries = self::query($workspace, $filters, $people)
            ->with(['user:id,name,email,avatar_path', 'machine:id,name'])
            ->when($filters['before'] ?? null, fn (Builder $q, int $id) => $q->where('id', '<', $id))
            ->orderByDesc('id')
            ->limit(self::PAGE + 1)
            ->get();

        $details = Gate::allows('manageMachines', $workspace);
        $origin = Gate::allows('manageSettings', $workspace);

        return Inertia::render('workspace/Activity', [
            'workspace' => $workspace->toPageProps(),
            'machines' => $machines->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'people' => $people->map(fn (array $p) => array_diff_key($p, ['match' => true]))->values(),
            'groups' => ActivityGroups::options(),
            'filters' => [
                'who' => $filters['who'] ?? null,
                'person' => $filters['person'] ?? null,
                'group' => $filters['group'] ?? null,
                'machine' => isset($filters['machine']) ? (int) $filters['machine'] : null,
                'when' => $filters['when'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'entries' => $entries->take(self::PAGE)->map(fn (Activity $a) => [
                'id' => $a->id,
                'at' => $a->created_at->toIso8601String(),
                'user' => ($actor = ActivityLine::actor($a)) === null ? null : ['name' => $actor['name'], 'avatar' => $actor['avatar'], 'support' => $actor['support']],
                'machine' => $a->machine ? ['id' => $a->machine->id, 'name' => $a->machine->name] : null,
                'action' => $a->action,
                'origin' => $origin ? self::origin($a) : null,
                ...ActivityLine::describe($a, $details),
            ])->values(),
            'hasMore' => $entries->count() > self::PAGE,
            'keepDays' => $workspace->activityRetentionDays(),
            'canExport' => $origin,
        ]);
    }

    /**
     * What the page shows as a CSV, every page of it (the filters on
     * screen apply), for audits. Owners and admins only, since it names
     * everyone in the workspace and where they signed in from. What staff
     * did reads as ServerOS Support, with no IP or browser: those were
     * the admin's.
     */
    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        Gate::authorize('manageSettings', $workspace);

        $people = self::people($workspace);
        $filters = self::filters($request, $workspace, $people);
        unset($filters['before']);
        Activity::record($workspace, 'activity.export', meta: array_filter(['filtered' => array_diff_key($filters, ['tz' => true]) !== []]));

        $file = 'activity-'.$workspace->slug.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($workspace, $filters, $people): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new RuntimeException('Couldn’t open the output stream for the export.');
            }
            fputcsv($out, ['Time (UTC)', 'Who', 'Email', 'By ServerOS Support', 'Source', 'MCP client', 'Action', 'What happened', 'Machine', 'Details', 'IP address', 'User agent', 'Request ID'], escape: '');

            // By id, so a row written meanwhile doesn't shift the pages.
            self::query($workspace, $filters, $people)
                ->with(['user:id,name,email', 'machine:id,name'])
                ->chunkByIdDesc(500, function ($entries) use ($out): void {
                    foreach ($entries as $a) {
                        $line = ActivityLine::describe($a, true);
                        $actor = ActivityLine::actor($a);
                        $origin = self::origin($a);
                        // As the page reads it: "web-1 stopped reporting" leads with the subject.
                        $what = $line['leads'] ? [$line['subject'], $line['verb']] : [$line['verb'], $line['subject']];
                        fputcsv($out, array_map(self::cell(...), [
                            $a->created_at->utc()->format('Y-m-d H:i:s'),
                            $actor['name'] ?? 'ServerOS',
                            $actor['email'] ?? '',
                            ($actor['support'] ?? false) ? 'yes' : 'no',
                            $origin['source'] ?? '',
                            $origin['client'] ?? '',
                            $a->action,
                            trim(implode(' ', array_filter([...$what, $line['after']]))),
                            $a->machine->name ?? '',
                            $line['detail'] ?? '',
                            $origin['ip'] ?? '',
                            $origin['userAgent'] ?? '',
                            $origin['requestId'] ?? '',
                        ]), escape: '');
                    }
                });

            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A cell as a spreadsheet should read it: text. Service names, commands
     * and browser names are whatever a member sent, and one starting with
     * =, +, - or @ would otherwise be run as a formula when the file is
     * opened; a leading apostrophe keeps it as written.
     */
    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Where it came from, for owners and admins. Staff's own IP and
     * browser stay out: on a ServerOS Support entry they'd be the admin's.
     *
     * @return array{source: string|null, client: string|null, device: string|null, ip: string|null, userAgent: string|null, requestId: string|null}
     */
    public static function origin(Activity $a): array
    {
        // Only a person's own request is theirs to see: not staff's on a
        // Support entry, and not whoever's set off an entry nobody did (a
        // trial extended from the admin page) or a workflow's steps.
        $hidden = $a->impersonation_session_id !== null || ! $a->byPerson() || $a->source === 'workflow';
        $via = $a->meta['via'] ?? null;

        return [
            'source' => $a->source ?? ($via === 'mcp' ? 'mcp' : null),
            // The AI app or API key it came through.
            'client' => in_array($via, ['mcp', 'api', 'scim'], true) && is_string($a->meta['client'] ?? null) ? $a->meta['client'] : null,
            'device' => $hidden ? null : Device::label($a->user_agent),
            'ip' => $hidden ? null : $a->ip,
            'userAgent' => $hidden ? null : $a->user_agent,
            'requestId' => $a->request_id,
        ];
    }

    /**
     * Everyone whose doings are in the log, for the Who menu: members
     * first, then people who've left (by their snapshot, if their account
     * is gone too). A person's value is their user id, or "gone-…" for a
     * deleted account, so no email goes in the address bar.
     *
     * @return Collection<int, array{value: string, name: string, avatar: string|null, left: bool, match: array{0: string, 1: string}}>
     */
    public static function people(Workspace $workspace): Collection
    {
        $members = $workspace->members()->orderBy('name')->get(['users.id', 'name', 'email', 'avatar_path']);
        $logged = Activity::query()->where('workspace_id', $workspace->id)->whereNotNull('user_id')->select('user_id');
        $former = User::query()->whereIn('id', $logged)->whereNotIn('id', $members->modelKeys())->orderBy('name')->get(['id', 'name', 'email', 'avatar_path']);
        $deleted = Activity::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('user_id')->whereNull('impersonation_session_id')->whereNotNull('actor_name')
            ->selectRaw('actor_email, max(actor_name) as actor_name')
            ->groupBy('actor_email')
            ->orderBy('actor_name')
            ->get();

        $user = fn (User $u, bool $left) => ['value' => (string) $u->id, 'name' => $u->name, 'avatar' => $u->avatar, 'left' => $left, 'match' => ['user', (string) $u->id]];

        return collect([
            ...$members->map(fn (User $u) => $user($u, false)),
            ...$former->map(fn (User $u) => $user($u, true)),
            ...$deleted->map(function (Activity $a) {
                return [
                    'value' => AuditContext::goneKey($a->actor_email ?? (string) $a->actor_name),
                    'name' => (string) $a->actor_name,
                    'avatar' => null,
                    'left' => true,
                    'match' => $a->actor_email !== null ? ['email', $a->actor_email] : ['name', (string) $a->actor_name],
                ];
            }),
        ])->values();
    }

    /**
     * @param  Collection<int, array{value: string, name: string, avatar: string|null, left: bool, match: array{0: string, 1: string}}>  $people
     * @return array<string, mixed>
     */
    private static function filters(Request $request, Workspace $workspace, Collection $people): array
    {
        return array_filter($request->validate([
            'who' => ['nullable', Rule::in(['people', 'system', 'support'])],
            'person' => ['nullable', 'string', Rule::in($people->pluck('value')->all())],
            'group' => ['nullable', Rule::in(array_keys(ActivityGroups::GROUPS))],
            'machine' => ['nullable', 'integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
            'when' => ['nullable', Rule::in(array_keys(self::WHEN))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tz' => ['nullable', 'timezone:all'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]), fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The workspace's entries, filtered. Dates are days in the reader's
     * time zone.
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, array{value: string, name: string, avatar: string|null, left: bool, match: array{0: string, 1: string}}>  $people
     * @return Builder<Activity>
     */
    private static function query(Workspace $workspace, array $filters, Collection $people): Builder
    {
        $tz = $filters['tz'] ?? 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = isset($filters['when'])
            ? $today->subDays(self::WHEN[$filters['when']] - 1)
            : (isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $tz)->startOfDay() : null);
        $to = ! isset($filters['when']) && isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $tz)->endOfDay() : null;
        $person = isset($filters['person']) ? $people->firstWhere('value', $filters['person']) : null;

        return Activity::query()
            ->where('workspace_id', $workspace->id)
            ->when(($filters['who'] ?? null) === 'people', fn (Builder $q) => $q->byPeople())
            ->when(($filters['who'] ?? null) === 'system', fn (Builder $q) => $q->bySystem())
            ->when(($filters['who'] ?? null) === 'support', fn (Builder $q) => $q->whereNotNull('impersonation_session_id'))
            ->when($person, fn (Builder $q, array $p) => match ($p['match'][0]) {
                'user' => $q->where('user_id', (int) $p['match'][1]),
                default => $q->whereNull('user_id')->whereNull('impersonation_session_id')->where($p['match'][0] === 'email' ? 'actor_email' : 'actor_name', $p['match'][1]),
            })
            ->when($filters['group'] ?? null, fn (Builder $q, string $group) => ActivityGroups::scope($q, $group))
            ->when($filters['machine'] ?? null, fn (Builder $q, int|string $id) => $q->where('machine_id', (int) $id))
            ->when($from, fn (Builder $q, CarbonImmutable $at) => $q->where('created_at', '>=', $at->utc()))
            ->when($to, fn (Builder $q, CarbonImmutable $at) => $q->where('created_at', '<=', $at->utc()));
    }
}
