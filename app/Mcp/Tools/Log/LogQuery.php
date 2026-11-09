<?php

namespace App\Mcp\Tools\Log;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Logs\LogStore;
use App\Support\StatusPages\StatusReport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use PDOException;
use Throwable;

/**
 * The Logs page's filters for the log tools: which machine and service,
 * how severe, what text, and when; each resolved the way an agent names
 * things, and the query they make. Also how a line reads to an agent,
 * JSON lines shown by their message as the page shows them. Lines are
 * read from wherever the workspace keeps them (LogStore).
 */
final class LogQuery
{
    /** Most severe first. */
    public const LEVELS = LogEntry::LEVELS;

    /** Keys a structured line's message, level and time come from, left out of its fields (as the page does). */
    private const QUIET_KEYS = ['level', 'lvl', 'severity', 'ts', 'time', 'timestamp', '@timestamp', 'msg', 'message', 'logger', 'name'];

    /** Distinct services looked at to match a name or label, as the page's picker does. */
    private const SERVICE_SCAN = 1000;

    /**
     * @param  Collection<int, Machine>  $machines  the workspace's machines
     * @param  list<string>|null  $levels  null for every level
     * @param  array{names: list<string>, keys: list<string>}|null  $service
     */
    public function __construct(
        public readonly Collection $machines,
        public readonly ?Machine $machine = null,
        public readonly ?array $service = null,
        public readonly ?string $level = null,
        public readonly ?array $levels = null,
        public readonly ?string $text = null,
        public readonly ?CarbonImmutable $since = null,
        public readonly ?CarbonImmutable $until = null,
        public readonly ?string $serviceAsked = null,
        public readonly ?string $connection = null,
    ) {}

    /**
     * Run a log tool's reads, turning a workspace's own log database not
     * answering into an error the agent can read.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function reachable(Workspace $workspace, Closure $read): mixed
    {
        try {
            return $read();
        } catch (QueryException|PDOException $e) {
            if (! LogStore::isExternal($workspace)) {
                throw $e;
            }
            LogStore::markUnreachable($workspace, $e);

            throw new ToolError("Can’t reach {$workspace->name}’s log database, where its lines are kept: ".LogStore::reason($e).' New lines are held for a while and stored once it answers. Try again later.');
        }
    }

    /**
     * The filters' schema, shared by the log tools.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(string $sinceDefault = ''): array
    {
        return [
            'machine' => Schema::machine('Only this machine’s lines: its id or name.'),
            'service' => Schema::string('Only this service’s lines: its container or unit name as the logs show it (like umami-umami-1 or nginx.service), its key, its label (like “Umami”), or, with machine, anything list_services knows it by.', maxLength: 300),
            'level' => Schema::string('This level and anything worse: error (errors only), warn (warnings and errors, like the page’s Warnings), info (all but debug), or debug (everything, the default).', self::LEVELS),
            'q' => Schema::string('Only lines containing this text (any case), like the page’s search.', maxLength: 200),
            'since' => Schema::string('Only lines written at or after this: an ISO 8601 time (like 2026-05-01T09:00:00Z), or how long ago, like 15m, 1h, 6h, 24h or 7d.'.$sinceDefault, maxLength: 40),
            'until' => Schema::string('Only lines written at or before this: an ISO 8601 time (a date alone means the end of that day), or how long ago, like 1h.', maxLength: 40),
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'machine' => ['nullable', 'max:120', self::reference()],
            'service' => ['nullable', 'string', 'max:300'],
            'level' => ['nullable', 'string', Rule::in(self::LEVELS)],
            'q' => ['nullable', 'string', 'max:200'],
            'since' => ['nullable', 'string', 'max:40'],
            'until' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * The filters from checked arguments.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Machine>|null  $machines  the workspace's machines, if already at hand
     */
    public static function from(McpContext $context, array $data, ?Collection $machines = null): self
    {
        $machines ??= $context->workspace->machines()->orderBy('name')->get();
        $machine = isset($data['machine']) ? $context->machine($data['machine']) : null;
        $connection = LogStore::connection($context->workspace);
        $level = $data['level'] ?? null;

        $since = isset($data['since']) ? self::time('since', (string) $data['since'], false) : null;
        $until = isset($data['until']) ? self::time('until', (string) $data['until'], true) : null;
        if ($since !== null && $until !== null && $since->gt($until)) {
            throw new ToolError('since is after until: nothing can match. Swap them?');
        }

        $text = isset($data['q']) && trim((string) $data['q']) !== '' ? trim((string) $data['q']) : null;
        $asked = isset($data['service']) && trim((string) $data['service']) !== '' ? trim((string) $data['service']) : null;

        return new self(
            machines: $machines,
            machine: $machine,
            service: $asked !== null ? self::service($context, $connection, $machines, $machine, $asked) : null,
            level: $level,
            levels: $level === null || $level === 'debug' ? null : array_slice(self::LEVELS, 0, array_search($level, self::LEVELS, true) + 1),
            text: $text,
            since: $since,
            until: $until,
            serviceAsked: $asked,
            connection: $connection,
        );
    }

    /**
     * Lines that match, as LogController's query makes them.
     *
     * @return Builder<LogEntry>
     */
    public function query(): Builder
    {
        return LogEntry::on($this->connection ?? LogStore::ours())
            ->whereIn('machine_id', $this->machine !== null ? [$this->machine->id] : $this->machines->modelKeys())
            ->when($this->service, fn (Builder $q, array $s) => $q->where(fn (Builder $w) => $w
                ->whereIn('service', $s['names'])
                ->when($s['keys'] !== [], fn (Builder $k) => $k->orWhereIn('service_key', $s['keys']))))
            ->when($this->levels, fn (Builder $q, array $levels) => $q->whereIn('level', $levels))
            ->when($this->since, fn (Builder $q, CarbonImmutable $at) => $q->where('logged_at', '>=', $at->getTimestampMs()))
            ->when($this->until, fn (Builder $q, CarbonImmutable $at) => $q->where('logged_at', '<=', $at->getTimestampMs()))
            ->when($this->text, fn (Builder $q, string $text) => $q->whereLike('message', '%'.addcslashes($text, '%_\\').'%'));
    }

    /**
     * The filters as the answer echoes them.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'machine' => $this->machine === null ? null : ['id' => $this->machine->id, 'name' => $this->machine->name],
            'service' => $this->serviceAsked === null ? null : ['asked' => $this->serviceAsked, 'matches' => $this->service['names'] ?? []],
            'level' => $this->level,
            'q' => $this->text,
            'since' => $this->since?->toIso8601String(),
            'until' => $this->until?->toIso8601String(),
        ];
    }

    /** The Logs page with the filters it has. */
    public function url(McpContext $context): string
    {
        $names = $this->service['names'] ?? [];
        $query = array_filter([
            'machine' => $this->machine?->id,
            'service' => count($names) === 1 ? $names[0] : null,
            'level' => in_array($this->level, ['error', 'warn'], true) ? $this->level : null,
            'q' => $this->text,
        ], fn ($v) => $v !== null);

        return $context->url('/logs'.($query !== [] ? '?'.http_build_query($query) : ''));
    }

    /**
     * One line for an agent. A JSON line with a message reads as that
     * message, with its logger and the rest of its fields beside it.
     *
     * @param  array<int, string>  $machineNames  by id
     * @return array<string, mixed>
     */
    public static function line(LogEntry $line, array $machineNames, int $maxChars): array
    {
        $entry = [
            'id' => $line->id,
            'time' => CarbonImmutable::createFromTimestampMsUTC($line->logged_at)->format('Y-m-d\TH:i:s.v\Z'),
            'machine' => ['id' => $line->machine_id, 'name' => $machineNames[$line->machine_id] ?? null],
            'service' => $line->service,
            'serviceLabel' => StatusReport::friendlyName($line->service),
            'serviceKey' => $line->service_key,
            'level' => $line->level,
            'stream' => $line->stream,
            'message' => $line->message,
        ];

        if (($structured = self::structured($line->message)) !== null) {
            $entry = [...$entry, ...$structured];
        }

        if (mb_strlen($entry['message']) > $maxChars) {
            $entry['message'] = mb_substr($entry['message'], 0, $maxChars).'…';
            $entry['truncated'] = true;
        }

        return $entry;
    }

    /**
     * A JSON line's message, logger and other fields, as the page reads
     * them; null for anything else.
     *
     * @return array{message: string, structured: true, logger: string|null, fields: array<string, mixed>|null}|null
     */
    public static function structured(string $message): ?array
    {
        $text = trim($message);
        if (! str_starts_with($text, '{') || ! str_ends_with($text, '}')) {
            return null;
        }

        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }

        $said = $data['msg'] ?? $data['message'] ?? null;
        if (! is_string($said)) {
            return null;
        }
        $logger = $data['logger'] ?? $data['name'] ?? null;
        $fields = array_diff_key($data, array_flip(self::QUIET_KEYS));

        return [
            'message' => $said,
            'structured' => true,
            'logger' => is_string($logger) ? $logger : null,
            'fields' => $fields === [] ? null : $fields,
        ];
    }

    /**
     * What a service filter matches: names lines were logged under and
     * service keys. On a machine, anything list_services knows it by; and
     * across the workspace, a line's name, key or label (any case).
     *
     * @param  Collection<int, Machine>  $machines
     * @return array{names: list<string>, keys: list<string>}
     */
    private static function service(McpContext $context, string $connection, Collection $machines, ?Machine $machine, string $reference): array
    {
        $names = [$reference];
        $keys = [$reference];
        $lower = mb_strtolower($reference);

        if ($machine !== null) {
            try {
                $found = $context->service($machine, $reference);
                $keys[] = (string) $found['key'];
                if (is_string($found['name'] ?? null)) {
                    $names[] = $found['name'];
                    $names[] = ltrim($found['name'], '/');
                }
            } catch (ToolError) {
                // Gone from the machine since, perhaps; its lines are still here.
            }
        }

        $logged = LogEntry::on($connection)
            ->whereIn('machine_id', $machine !== null ? [$machine->id] : $machines->modelKeys())
            ->select(['machine_id', 'service'])
            ->distinct()
            ->limit(self::SERVICE_SCAN)
            ->get();

        foreach ($logged as $line) {
            if (mb_strtolower($line->service) === $lower || mb_strtolower(StatusReport::friendlyName($line->service)) === $lower) {
                $names[] = $line->service;
            }
        }

        $names = array_values(array_unique($names));
        $keys = array_values(array_unique($keys));

        $any = LogEntry::on($connection)
            ->whereIn('machine_id', $machine !== null ? [$machine->id] : $machines->modelKeys())
            ->where(fn (Builder $w) => $w->whereIn('service', $names)->orWhereIn('service_key', $keys))
            ->exists();
        if (! $any && count($names) === 1 && count($keys) === 1) {
            $known = $logged->map(fn (LogEntry $l) => StatusReport::friendlyName($l->service)." ({$l->service})")->unique()->sort()->take(15);

            throw new ToolError("No lines from a service “{$reference}”".($machine !== null ? " on {$machine->name}" : '').'.'
                .($known->isNotEmpty() ? ' Services with lines: '.$known->implode(', ').'.' : ' Nothing has logged '.($machine !== null ? 'there' : 'here').' yet.'));
        }

        return ['names' => $names, 'keys' => $keys];
    }

    /**
     * A time the agent gave: ISO 8601, or how long ago ("15m", "6h",
     * "7d"). A date alone as `until` means the end of that day.
     */
    public static function time(string $name, string $value, bool $endOfDay): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,5})\s*(m|min|mins|minutes?|h|hours?|d|days?|w|weeks?)$/i', $value, $m) === 1) {
            $minutes = (int) $m[1] * match (strtolower($m[2][0])) {
                'm' => 1,
                'h' => 60,
                'd' => 1440,
                default => 10080, // w: weeks, all the pattern leaves
            };

            return CarbonImmutable::now()->utc()->subMinutes($minutes);
        }

        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new ToolError("{$name} isn’t a time: use ISO 8601 (like 2026-05-01T09:00:00Z) or how long ago (like 15m, 6h or 7d).");
        }

        return $endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $at->endOfDay() : $at;
    }

    /**
     * A rule for references to machines: an id or a name.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function reference(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! (is_string($value) && trim($value) !== '') && ! is_int($value)) {
                $fail("The {$attribute} must be an id or a name.");
            }
        };
    }
}
