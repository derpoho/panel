<?php

namespace App\Mcp\Tools\Service;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\DaemonJob;

/**
 * Read a service's latest log lines straight from the machine, as its
 * Logs tab does: a service_logs job, waited on for its answer.
 */
final class GetServiceLogs extends Tool
{
    /** Longest a line is returned, and most text in one answer. */
    private const LINE_CHARS = 2000;

    private const TOTAL_CHARS = 200_000;

    public function __construct(private readonly ServiceWork $work) {}

    public function name(): string
    {
        return 'get_service_logs';
    }

    public function title(): string
    {
        return 'Read a service’s logs';
    }

    public function description(): string
    {
        return 'Fetches the latest log lines of a service from its machine (docker logs, journalctl…), oldest first, like the Logs tab on its page. lines is how many (10–5000, default 200); contains keeps only lines with that text (any case). It asks the machine and waits for the answer (wait, default 20 seconds); if the machine is slow, it returns the job instead: call get_job with its id for the lines (result.lines). '
            .'The service must be adopted by ServerOS (managed: true) and the machine online. For logs ServerOS has collected over time, across services, use the Logs tools instead.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
            'lines' => Schema::integer('How many of the latest lines to fetch (10–5000, default 200).', 10, 5000),
            'contains' => Schema::string('Only return lines containing this text (any case).', maxLength: 200),
            'wait' => ServiceWork::waitSchema(20),
        ], ['machine', 'service']);
    }

    public function permission(): WorkspacePermission
    {
        // The service page's Logs tab is for people who manage machines.
        return WorkspacePermission::ManageMachines;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            ...ServiceWork::serviceArguments(),
            'lines' => ['sometimes', 'integer', 'between:10,5000'],
            'contains' => ['sometimes', 'string', 'max:200'],
            'wait' => ServiceWork::waitRules(),
        ]);
        [$machine, $service] = ServiceWork::find($context, $data);
        ServiceWork::requireManaged($service);

        $key = (string) $service['key'];
        $lines = (int) ($data['lines'] ?? 200);
        $job = $this->work->queue($context, $machine, 'service_logs', ['type' => 'service_logs', 'service' => $key, 'lines' => $lines], $key);
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 20));

        $answer = [
            'service' => ['key' => $key, 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'jobId' => $job->id,
            'state' => $job->state,
        ];

        if ($job->state !== 'succeeded') {
            return [...$answer, 'job' => ServiceView::job($context, $job, 10), 'note' => $job->isActive()
                ? 'The machine hasn’t answered yet. Call get_job with this jobId in a few seconds: the lines are in result.lines.'
                : 'Couldn’t read the logs: see job.error.'];
        }

        return [...$answer, ...$this->lines($job, $data['contains'] ?? null)];
    }

    /** @return array<string, mixed> */
    private function lines(DaemonJob $job, ?string $contains): array
    {
        $all = is_array($job->result['lines'] ?? null) ? $job->result['lines'] : ($job->log ?? []);
        $all = array_values(array_filter($all, 'is_string'));
        $needle = mb_strtolower(trim((string) $contains));
        $matching = $needle === '' ? $all : array_values(array_filter($all, fn (string $line) => str_contains(mb_strtolower($line), $needle)));

        // The newest lines matter most: keep from the end.
        $kept = [];
        $chars = 0;
        $cut = false;
        for ($i = count($matching) - 1; $i >= 0; $i--) {
            $line = mb_strlen($matching[$i]) > self::LINE_CHARS ? mb_substr($matching[$i], 0, self::LINE_CHARS).'…' : $matching[$i];
            if ($chars + mb_strlen($line) > self::TOTAL_CHARS) {
                $cut = true;
                break;
            }
            $chars += mb_strlen($line);
            $kept[] = $line;
        }

        return [
            'lines' => array_reverse($kept),
            'count' => count($kept),
            'fetched' => count($all),
            'truncated' => $cut,
            'note' => $cut ? 'Too much to return at once: these are the newest lines. Ask for fewer lines, or filter with contains.' : null,
        ];
    }
}
