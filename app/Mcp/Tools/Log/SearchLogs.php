<?php

namespace App\Mcp\Tools\Log;

use App\Http\Controllers\LogController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\LogEntry;
use App\Support\Logs\LogIngest;
use App\Support\Logs\LogStore;

/**
 * The Logs page for an agent: every line the workspace's services wrote,
 * filtered by machine, service, level, text and time, paged back through
 * with a cursor, followed forward, or opened in the context of the lines
 * around it. Lines come from wherever the workspace keeps them.
 */
final class SearchLogs extends Tool
{
    public const DEFAULT_LIMIT = 100;

    /** As many as one poll of the page returns. */
    public const MAX_LIMIT = LogController::FOLLOW_LIMIT;

    public const DEFAULT_CHARS = 2000;

    public function name(): string
    {
        return 'search_logs';
    }

    public function title(): string
    {
        return 'Search logs';
    }

    public function description(): string
    {
        return 'Searches the lines the workspace’s services (containers and systemd units) wrote, as the dashboard’s Logs page does; secrets are taken out on the machine before lines are sent. Lines are kept as long as the plan allows (retentionDays). '
            .'Returns entries in the order they were written, oldest first: the newest `limit` lines that match (default '.self::DEFAULT_LIMIT.', at most '.self::MAX_LIMIT.'). Each has its id, time (ISO 8601, UTC, to the millisecond), machine, service (its container or unit name), serviceLabel, serviceKey, level (error, warn, info or debug, read from the line or from journald), stream (stdout, stderr or journal) and message. A JSON line reads as the dashboard shows it: message is its msg/message, with structured: true, its logger, and the rest of its fields; the line as written is in the dashboard. Messages over maxChars are cut (truncated: true). '
            .'Filters: machine, service, level (that level and worse), q (text, any case), since and until. Paging: for earlier lines pass before = olderCursor; for lines written after these (or to follow a log as it runs) pass after = newerCursor, which returns the next lines in order. `more` says whether there are more in the direction you asked. around = a line’s id returns the '.LogController::CONTEXT.' lines either side of it from the same service, ignoring the filters, to see what led up to an error.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            ...LogQuery::schema(),
            'limit' => Schema::integer('How many lines to return (1–'.self::MAX_LIMIT.', default '.self::DEFAULT_LIMIT.').', 1, self::MAX_LIMIT),
            'before' => Schema::integer('Earlier lines: those before this line id (olderCursor of the previous answer).', 1),
            'after' => Schema::integer('Later lines: those after this line id (newerCursor of the previous answer), oldest first.', 0),
            'around' => Schema::integer('A line’s id: return it with the lines either side from the same service and machine, ignoring the filters.', 1),
            'maxChars' => Schema::integer('Longest message to return in full; longer ones are cut (200–'.LogIngest::MAX_MESSAGE.', default '.self::DEFAULT_CHARS.').', 200, LogIngest::MAX_MESSAGE),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return LogQuery::reachable($context->workspace, fn () => $this->search($context, $arguments));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function search(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...LogQuery::rules(),
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
            'around' => ['nullable', 'integer', 'min:1'],
            'maxChars' => ['nullable', 'integer', 'min:200', 'max:'.LogIngest::MAX_MESSAGE],
        ]);
        if (count(array_filter([isset($data['before']), isset($data['after']), isset($data['around'])])) > 1) {
            throw new ToolError('Use one of before, after and around at a time.');
        }

        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);
        $chars = (int) ($data['maxChars'] ?? self::DEFAULT_CHARS);
        $machines = $context->workspace->machines()->orderBy('name')->get();
        $names = $machines->pluck('name', 'id')->all();
        $retention = $context->workspace->logRetentionDays();

        if (isset($data['around'])) {
            return $this->around($context, $machines->modelKeys(), $names, (int) $data['around'], $chars, $retention);
        }

        $filters = LogQuery::from($context, $data, $machines);
        $query = $filters->query();

        if (isset($data['after'])) {
            $lines = $query->where('id', '>', (int) $data['after'])->orderBy('id')->limit($limit + 1)->get();
            $page = $lines->take($limit);
        } else {
            $lines = $query
                ->when(isset($data['before']), fn ($q) => $q->where('id', '<', (int) $data['before']))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();
            $page = $lines->take($limit)->reverse()->values();
        }
        $more = $lines->count() > $limit;

        $shipping = $page->isNotEmpty() || LogStore::entries($context->workspace)->whereIn('machine_id', $machines->modelKeys())->exists();

        return [
            'entries' => $page->map(fn (LogEntry $line) => LogQuery::line($line, $names, $chars))->values()->all(),
            'count' => $page->count(),
            'more' => $more,
            'olderCursor' => $page->first()?->id,
            // With nothing back, still somewhere to follow from.
            'newerCursor' => $page->last()->id ?? match (true) {
                isset($data['after']) => (int) $data['after'],
                isset($data['before']) => (int) $data['before'] - 1,
                default => $this->newest($context, $machines->modelKeys()),
            },
            'filters' => $filters->describe(),
            'retentionDays' => $retention,
            'note' => $page->isNotEmpty() ? null : $this->quiet($machines->isEmpty(), $shipping, isset($data['after'])),
            'url' => $filters->url($context),
        ];
    }

    /**
     * A line in the context of the lines around it, as the page opens one.
     *
     * @param  array<int, int|string>  $machineIds
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function around(McpContext $context, array $machineIds, array $names, int $id, int $chars, int $retention): array
    {
        $line = LogStore::entries($context->workspace)->whereIn('machine_id', $machineIds)->find($id);
        if ($line === null) {
            throw new ToolError("No log line #{$id} in {$context->workspace->name}. It may be older than the {$retention} ".($retention === 1 ? 'day' : 'days').' lines are kept.');
        }

        $same = fn () => LogStore::entries($context->workspace)
            ->where('machine_id', $line->machine_id)
            ->where('service', $line->service);

        $before = $same()->where('id', '<', $line->id)->orderByDesc('id')->limit(LogController::CONTEXT)->get()->reverse();
        $after = $same()->where('id', '>', $line->id)->orderBy('id')->limit(LogController::CONTEXT)->get();
        $lines = $before->push($line)->concat($after)->values();

        return [
            'anchor' => $line->id,
            'entries' => $lines->map(fn (LogEntry $l) => LogQuery::line($l, $names, $chars))->all(),
            'count' => $lines->count(),
            'more' => false,
            'olderCursor' => $lines->first()?->id,
            'newerCursor' => $lines->last()?->id,
            'filters' => [
                'machine' => ['id' => $line->machine_id, 'name' => $names[$line->machine_id] ?? null],
                'service' => ['asked' => $line->service, 'matches' => [$line->service]],
            ],
            'retentionDays' => $retention,
            'note' => 'Lines around #'.$line->id.' from '.$line->service.'. For more of this service, use search_logs with machine and service and before/after these cursors.',
            'url' => $context->url('/logs?'.http_build_query(['machine' => $line->machine_id, 'service' => $line->service])),
        ];
    }

    /** @param  array<int, int|string>  $machineIds */
    private function newest(McpContext $context, array $machineIds): int
    {
        return (int) LogStore::entries($context->workspace)->whereIn('machine_id', $machineIds)->max('id');
    }

    /** Why nothing came back, in the page's words. */
    private function quiet(bool $noMachines, bool $shipping, bool $following): string
    {
        return match (true) {
            $noMachines => 'No logs yet: connect a machine and every service on it sends its logs here.',
            ! $shipping => 'Waiting for lines: containers and systemd services send what they write as they write it, within a few seconds of the daemon updating.',
            $following => 'Nothing new since that line yet. Call again later with the same after to keep following.',
            default => 'No lines match. Nothing kept matches these filters; widen them (since, level, q) or check the service name.',
        };
    }
}
