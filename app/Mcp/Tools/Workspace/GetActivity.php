<?php

namespace App\Mcp\Tools\Workspace;

use App\Enums\WorkspacePermission;
use App\Http\Controllers\ActivityController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Activity;
use App\Models\User;
use App\Support\Activity\ActivityGroups;
use App\Support\Activity\ActivityLine;
use App\Support\Audit\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The workspace's activity log, newest first, as the Activity page shows
 * it: who did what (or what happened on its own), to what, on which
 * machine, and when. Filters go further than the page's, for questions
 * like "who restarted the database yesterday?".
 */
final class GetActivity extends Tool
{
    public const MAX_LIMIT = 100;

    public function name(): string
    {
        return 'get_activity';
    }

    public function title(): string
    {
        return 'Read the activity log';
    }

    public function description(): string
    {
        return 'The workspace’s activity log, newest first, as the dashboard’s Activity page shows it: deploys, restarts, console commands, settings changes, members and roles, and things that happened on their own (machines going quiet, services stopping, monitors going down, backups failing, workflows running). Kept for 90 to 365 days depending on the plan (`keepDays` says how long here). '
            .'Each entry has `what`, the sentence the dashboard shows (like “Ada restarted umami on web-1” or “web-1 stopped reporting”), its parts, `action` (like service.restart or monitor.down), who did it (null when it happened on its own; `support` true when ServerOS Support did it for the workspace), the machine, when (`at`, ISO 8601), `source` (web, mcp, workflow, machine, api or system), and `viaMcp`: whether it was done through MCP, with `client`, the AI app or API key it came from. Console commands are shown word for word only to people who can manage machines. '
            .'Filters: machine; who (people, system, or support for what ServerOS Support did); person (a member’s id, email or name, “me”, or someone who has left, by name or email); group (a kind of action as the page groups them: '.implode(', ', array_keys(ActivityGroups::GROUPS)).'); action (an action or how it starts: “service” for all service actions, “monitor.down”, “backup”); viaMcp; since and until (ISO 8601 dates or times). For older entries pass `before` = the `nextCursor` of the previous answer.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('Only entries about this machine: its id or name.'),
            'who' => Schema::string('Only what people did (people), only what happened on its own (system), or only what ServerOS Support did for the workspace (support).', ['people', 'system', 'support']),
            'person' => Schema::string('Only what this person did: their user id, email or name, or “me” for the person you act as. People who have left are found too.', maxLength: 255),
            'group' => Schema::string('Only this kind of action, as the Activity page groups them.', array_keys(ActivityGroups::GROUPS)),
            'action' => Schema::string('Only entries whose action is this or starts with it, like “service”, “service.restart”, “machine.maintenance” or “workflow”.', maxLength: 60),
            'viaMcp' => Schema::boolean('true: only what was done through MCP (by AI apps and API keys); false: only what wasn’t.'),
            'since' => Schema::string('Only entries at or after this time (ISO 8601, like 2026-05-01 or 2026-05-01T09:00:00Z).', maxLength: 40),
            'until' => Schema::string('Only entries at or before this time (ISO 8601). A date alone means the end of that day.', maxLength: 40),
            'limit' => Schema::integer('How many entries to return (default '.ActivityController::PAGE.').', 1, self::MAX_LIMIT),
            'before' => Schema::integer('For older entries: the nextCursor from the previous answer.', 1),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(Rules::given($arguments), [
            'machine' => ['nullable', 'max:120', Rules::reference(...)],
            'who' => ['nullable', 'string', 'in:people,system,support'],
            'group' => ['nullable', 'string', 'in:'.implode(',', array_keys(ActivityGroups::GROUPS))],
            'person' => ['nullable', 'max:255', Rules::reference(...)],
            'action' => ['nullable', 'string', 'max:60'],
            'viaMcp' => ['nullable', 'boolean'],
            'since' => ['nullable', 'string', 'max:40', 'date'],
            'until' => ['nullable', 'string', 'max:40', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $workspace = $context->workspace;
        $machine = ($data['machine'] ?? null) !== null ? $context->machine($data['machine']) : null;
        $person = ($data['person'] ?? null) !== null ? $this->person($context, (string) $data['person']) : null;
        $since = isset($data['since']) ? CarbonImmutable::parse($data['since'])->utc() : null;
        $until = isset($data['until']) ? $this->until((string) $data['until']) : null;
        if ($since !== null && $until !== null && $since->gt($until)) {
            throw new ToolError('since is after until: nothing can match. Swap them?');
        }
        $viaMcp = isset($data['viaMcp']) ? (bool) $data['viaMcp'] : null;
        $action = isset($data['action']) ? rtrim(mb_strtolower(trim((string) $data['action'])), '.') : null;
        $limit = (int) ($data['limit'] ?? ActivityController::PAGE);

        $entries = Activity::query()
            ->where('workspace_id', $workspace->id)
            ->with(['user:id,name', 'machine:id,name'])
            ->when(($data['who'] ?? null) === 'people', fn (Builder $q) => $q->byPeople())
            ->when(($data['who'] ?? null) === 'system', fn (Builder $q) => $q->bySystem())
            ->when($machine, fn (Builder $q) => $q->where('machine_id', $machine->id))
            ->when(($data['who'] ?? null) === 'support', fn (Builder $q) => $q->whereNotNull('impersonation_session_id'))
            ->when($person, fn (Builder $q, array $p) => $p[0] === 'user'
                ? $q->where('user_id', $p[1])
                : $q->whereNull('user_id')->whereNull('impersonation_session_id')->where($p[0], $p[1]))
            ->when($data['group'] ?? null, fn (Builder $q, string $group) => ActivityGroups::scope($q, $group))
            // A plain prefix, not LIKE: action names have underscores in them.
            ->when($action !== null && $action !== '', fn (Builder $q) => $q->whereRaw('substr(action, 1, ?) = ?', [mb_strlen($action), $action]))
            ->when($viaMcp === true, fn (Builder $q) => $q->where('meta->via', 'mcp'))
            ->when($viaMcp === false, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('meta')->orWhereNull('meta->via')->orWhere('meta->via', '!=', 'mcp')))
            ->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->when($until, fn (Builder $q) => $q->where('created_at', '<=', $until))
            ->when($data['before'] ?? null, fn (Builder $q, int $id) => $q->where('id', '<', $id))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        // As on the page: the commands people ran only for those who could run them.
        $details = $context->can(WorkspacePermission::ManageMachines);
        $page = $entries->take($limit);
        $hasMore = $entries->count() > $limit;

        return [
            'entries' => $page->map(fn (Activity $a) => $this->entry($a, $details))->values()->all(),
            'hasMore' => $hasMore,
            'nextCursor' => $hasMore ? $page->last()->id : null,
            'keepDays' => $workspace->activityRetentionDays(),
            'url' => $context->url('/activity'.(($query = http_build_query(array_filter(['who' => $data['who'] ?? null, 'machine' => $machine?->id]))) !== '' ? "?{$query}" : '')),
        ];
    }

    /** @return array<string, mixed> */
    private function entry(Activity $a, bool $details): array
    {
        $line = ActivityLine::describe($a, $details);
        $via = $a->meta['via'] ?? null;
        $mcp = $via === 'mcp';

        return [
            'id' => $a->id,
            'at' => $a->created_at->toIso8601String(),
            'what' => ActivityLine::sentence($a, $line),
            'action' => $a->action,
            'who' => match (true) {
                $a->impersonation_session_id !== null => ['id' => null, 'name' => ActivityLine::actor($a)['name'] ?? AuditContext::SUPPORT, 'support' => true],
                $a->user !== null => ['id' => $a->user->id, 'name' => $a->user->name, 'support' => false],
                $a->actor_name !== null => ['id' => null, 'name' => $a->actor_name, 'support' => false],
                default => null,
            },
            'source' => $a->source,
            'verb' => $line['verb'],
            'subject' => $line['subject'],
            'after' => $line['after'],
            'detail' => $line['detail'],
            'tone' => $line['tone'],
            'machine' => $a->machine ? ['id' => $a->machine->id, 'name' => $a->machine->name] : null,
            'viaMcp' => $mcp,
            'viaApi' => $via === 'api',
            'client' => in_array($via, ['mcp', 'api'], true) ? ($a->meta['client'] ?? null) : null,
        ];
    }

    /**
     * Someone whose doings are in this workspace's log: a member by id,
     * email or name, anyone who has been one by id or name, or someone
     * whose account is gone by the name or email they had then.
     *
     * @return array{0: 'user', 1: int}|array{0: 'actor_email'|'actor_name', 1: string}
     */
    private function person(McpContext $context, string $reference): array
    {
        $reference = trim($reference);
        if (mb_strtolower($reference) === 'me') {
            return ['user', $context->user->id];
        }

        $workspace = $context->workspace;
        $lower = mb_strtolower($reference);
        $members = $workspace->members();
        $past = User::query()->whereIn('id', Activity::query()->where('workspace_id', $workspace->id)->whereNotNull('user_id')->select('user_id'));

        if (ctype_digit($reference)) {
            $found = (clone $members)->whereKey((int) $reference)->first()
                ?? (clone $past)->whereKey((int) $reference)->first();
            if ($found !== null) {
                return ['user', $found->id];
            }
        }

        if (str_contains($reference, '@')) {
            // Former members' emails are for those who could export them
            // (the CSV): not a way for anyone else to learn who's been here.
            $found = (clone $members)->whereRaw('lower(email) = ?', [$lower])->first()
                ?? ($context->can(WorkspacePermission::ManageSettings) ? (clone $past)->whereRaw('lower(email) = ?', [$lower])->first() : null);
            if ($found !== null) {
                return ['user', $found->id];
            }
        }

        $named = (clone $members)->whereRaw('lower(name) = ?', [$lower])->get();
        if ($named->isEmpty()) {
            $named = (clone $past)->whereRaw('lower(name) = ?', [$lower])->get();
        }
        if ($named->count() === 1) {
            return ['user', $named->first()->id];
        }
        if ($named->count() > 1) {
            throw new ToolError("Several people are called “{$reference}”: use their id (".$named->pluck('id')->implode(', ').') or, for members, their email.');
        }

        // Deleted accounts: only the name and email they had then are left.
        $column = str_contains($reference, '@') ? 'actor_email' : 'actor_name';
        $snapshot = Activity::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('user_id')->whereNull('impersonation_session_id')
            ->whereRaw("lower({$column}) = ?", [$lower])
            ->value($column);
        if (is_string($snapshot)) {
            return [$column, $snapshot];
        }

        throw new ToolError("No one called “{$reference}” has been in {$workspace->name}. Use a member’s id, email or name, or “me”.");
    }

    /** The end of a day given as a date alone; the time itself otherwise. */
    private function until(string $value): CarbonImmutable
    {
        $at = CarbonImmutable::parse($value)->utc();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? $at->endOfDay() : $at;
    }
}
