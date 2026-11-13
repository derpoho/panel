<?php

namespace App\Support\Assistant;

use App\Enums\WorkspacePermission;
use App\Jobs\DiagnoseFix;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Present;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\Service\ServiceWork;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\Machine;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use App\Support\Extensions\DocsSource;
use App\Support\Fixes\FixAllowance;
use App\Support\Projects\ProjectActions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AssistantAgent
{
    private const TTL_HOURS = 6;

    public const BUDGET_SECONDS = 840;

    public const CORE = ['get_workspace', 'list_machines', 'list_services', 'get_service', 'get_service_logs', 'list_deploys', 'list_templates', 'list_workflow_templates', 'get_job', 'search'];

    private const MAX_LOADED = 24;

    private const RESULT_CHARS = 6_000;

    private const OLD_RESULT_CHARS = 700;

    private const HISTORY = 40;

    private const DOCS_CHARS = 3_500;

    private const FOLLOW_SECONDS = 600;

    private const KEEPALIVE_SECONDS = 10;

    private const NO_APPROVAL = ['get_connection'];

    private const ALWAYS_ASK = [
        'install_packages', 'install_updates', 'create_package_rollout', 'open_port', 'close_port', 'reboot_machine',
        'create_workflow', 'update_workflow', 'run_workflow',
        'invite_member', 'change_member_role', 'update_workspace',
        'deploy_from_git', 'deploy_template', 'deploy_new_version', 'update_app_settings', 'set_app_domain', 'clone_service', 'connect_project_app',
        'create_machine_policy', 'update_machine_policy', 'update_backup_settings', 'update_alert_settings', 'start_maintenance',
        'adopt_service', 'mute_monitor', 'pause_monitor', 'post_status_incident', 'update_status_incident',
    ];

    private const SHOWS_PAGE = ['get_machine', 'get_service', 'get_app', 'get_workflow', 'get_workflow_run', 'get_monitor', 'get_status_page'];

    /** @var array<string, mixed> */
    private array $chat = [];

    private McpContext $context;

    /** @var callable(array<string, mixed>): void */
    private $emit;

    private int $toolCalls = 0;

    private float $deadline = 0;

    private bool $changed = false;

    private bool $nudged = false;

    public function __construct(
        private readonly AgentChat $model,
        private readonly AiProviders $providers,
        private readonly ToolRegistry $registry,
        private readonly McpServer $server,
        private readonly DocsSource $docs,
    ) {}

    /** @return array<string, mixed> */
    public static function load(?string $id, Workspace $workspace, User $user): array
    {
        $chat = $id !== null ? Cache::get(self::cacheKey($id)) : null;
        if (! is_array($chat) || $chat['workspace_id'] !== $workspace->id || $chat['user_id'] !== $user->id) {
            $chat = [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'messages' => [],
                'loaded' => [],
                'pending' => [],
                'allow_changes' => false,
            ];
        }

        return $chat;
    }

    /**
     * @param  array<string, mixed>  $chat
     * @return array<string, mixed>
     */
    public static function reload(array $chat): array
    {
        $stored = Cache::get(self::cacheKey($chat['id']));

        return is_array($stored) && $stored['workspace_id'] === $chat['workspace_id'] && $stored['user_id'] === $chat['user_id'] ? $stored : $chat;
    }

    /**
     * @param  array<string, mixed>  $chat
     * @param  callable(array<string, mixed>): void  $emit
     */
    public function ask(array $chat, Workspace $workspace, User $user, string $message, ?string $path, callable $emit, ?string $model = null): void
    {
        if ($model !== null) {
            $chat['model'] = $model;
        }
        $this->begin($chat, $workspace, $user, $emit);
        foreach ($this->chat['pending'] as $call) {
            $this->chat['messages'][] = ['role' => 'tool', 'id' => $call['id'], 'name' => $call['name'], 'content' => 'Not run: the person sent a new message instead of approving it.'];
            ($this->emit)(['type' => 'result', 'id' => $call['id'], 'ok' => false, 'state' => 'skipped', 'summary' => 'Skipped']);
        }
        $this->chat['pending'] = [];
        $this->chat['path'] = $path;
        $this->chat['messages'][] = ['role' => 'user', 'content' => $message];
        $this->loop();
    }

    /**
     * @param  array<string, mixed>  $chat
     * @param  callable(array<string, mixed>): void  $emit
     */
    public function decide(array $chat, Workspace $workspace, User $user, string $callId, bool $approve, bool $always, callable $emit): void
    {
        $this->begin($chat, $workspace, $user, $emit);
        $call = $this->chat['pending'][0] ?? null;
        if ($call === null || $call['id'] !== $callId) {
            ($this->emit)(['type' => 'error', 'message' => 'That change isn’t waiting any more.']);
            $this->save();

            return;
        }
        array_shift($this->chat['pending']);
        $this->save();

        if (! $approve) {
            $this->chat['messages'][] = ['role' => 'tool', 'id' => $call['id'], 'name' => $call['name'], 'content' => 'The person declined this, so it wasn’t run. Don’t try it again unless they ask; ask what they’d like instead.'];
            ($this->emit)(['type' => 'result', 'id' => $call['id'], 'ok' => false, 'state' => 'declined', 'summary' => 'Declined']);
            foreach ($this->chat['pending'] as $rest) {
                $this->chat['messages'][] = ['role' => 'tool', 'id' => $rest['id'], 'name' => $rest['name'], 'content' => 'Not run: the person declined the change before it.'];
                ($this->emit)(['type' => 'result', 'id' => $rest['id'], 'ok' => false, 'state' => 'skipped', 'summary' => 'Skipped']);
            }
            $this->chat['pending'] = [];
        } else {
            if ($always) {
                $this->chat['allow_changes'] = true;
            }
            $this->safeRun($call, approved: true);
            $this->save();
            if (! $this->drain()) {
                $this->save();

                return;
            }
        }

        $this->loop();
    }

    /**
     * @param  array<string, mixed>  $chat
     * @param  callable(array<string, mixed>): void  $emit
     */
    private function begin(array $chat, Workspace $workspace, User $user, callable $emit): void
    {
        $this->chat = $chat;
        $this->emit = $emit;
        $this->deadline = microtime(true) + self::BUDGET_SECONDS;
        $this->context = new McpContext($workspace, $user, via: 'assistant', grantedScopes: [Scopes::READ, Scopes::WRITE]);
        $ai = $this->providers->forChat($workspace, $chat['model'] ?? null);
        ($this->emit)(['type' => 'chat', 'id' => $chat['id'], 'model' => $ai['own'] ? "{$ai['provider']}:{$ai['model']}" : null]);
    }

    private function loop(): void
    {
        $ai = $this->providers->forChat($this->context->workspace, $this->chat['model'] ?? null);
        $instructions = null;

        try {
            for ($turn = 0; $turn < AssistantAllowance::MAX_TURNS; $turn++) {
                if (connection_aborted()) {
                    break;
                }
                if (microtime(true) > $this->deadline) {
                    ($this->emit)(['type' => 'text', 'text' => 'That took longer than one message can. Tell me to carry on and I’ll pick up where I stopped.']);
                    break;
                }
                $this->trim();
                $instructions ??= $this->instructions();
                $answer = $this->model->turn($ai, $instructions, $this->chat['messages'], $this->toolDefinitions());

                if ($answer['text'] !== '') {
                    ($this->emit)(['type' => 'text', 'text' => $answer['text']]);
                }
                if ($ai['provider'] !== 'openai') {
                    foreach ($answer['calls'] as $i => $call) {
                        $answer['calls'][$i]['id'] = 'call_'.Str::lower(Str::random(24));
                    }
                }
                $message = ['role' => 'assistant', 'content' => $answer['text'], 'calls' => $answer['calls']];
                if (isset($answer['openai_output'])) {
                    $message['openai_output'] = $answer['openai_output'];
                    $message['openai_model'] = $ai['model'];
                }
                $this->chat['messages'][] = $message;

                if ($answer['calls'] === []) {
                    if (! $this->changed && ! $this->nudged && self::claimsAction($answer['text'])) {
                        $this->nudged = true;
                        $this->chat['messages'][] = ['role' => 'user', 'content' => '(ServerOS: you described a change but didn’t make it, so nothing has happened yet. If you know what to do, call the tool now; the person approves it in the panel. If something is missing, ask for exactly that. Don’t say it’s started.)'];

                        continue;
                    }
                    if (! $this->nudged && ($named = $this->namesTool($answer['text'])) !== null) {
                        $this->nudged = true;
                        $this->chat['messages'][] = ['role' => 'user', 'content' => "(ServerOS: you wrote about {$named} instead of calling it. If it’s part of what they asked for, call it now; nobody else will.)"];

                        continue;
                    }
                    if (! $this->nudged && self::asksLeave($answer['text'])) {
                        $this->nudged = true;
                        $this->chat['messages'][] = ['role' => 'user', 'content' => '(ServerOS: don’t ask for the go-ahead in words: the person approves each change in the panel. If this step is part of what they asked for, call the tool now. Only ask if it’s a real choice they haven’t made.)'];

                        continue;
                    }
                    break;
                }

                $this->chat['pending'] = $answer['calls'];
                if (! $this->drain()) {
                    $this->save();

                    return;
                }

                if ($turn === AssistantAllowance::MAX_TURNS - 1) {
                    ($this->emit)(['type' => 'text', 'text' => 'That took more steps than I’m allowed for one message. Tell me to carry on and I’ll pick up where I stopped.']);
                }
            }
        } catch (RuntimeException $e) {
            ($this->emit)(['type' => 'error', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);
            ($this->emit)(['type' => 'error', 'message' => 'Something went wrong. Try again in a moment.']);
        }

        $this->save();
    }

    private function drain(): bool
    {
        while (($call = $this->chat['pending'][0] ?? null) !== null) {
            $tool = $this->registry->find($call['name']);
            if ($tool !== null && $this->usable($tool) && ! $this->isLoaded($tool)) {
                $this->loadTool($tool);
            }
            if ($tool !== null && $this->needsApproval($tool) && $this->usable($tool)) {
                $this->changed = true;
                ($this->emit)($this->approval($call, $tool));

                return false;
            }
            array_shift($this->chat['pending']);

            if ($this->toolCalls >= AssistantAllowance::MAX_TOOL_CALLS || microtime(true) > $this->deadline) {
                $this->chat['messages'][] = ['role' => 'tool', 'id' => $call['id'], 'name' => $call['name'], 'content' => 'Not run: this message has used all its steps or time. Tell the person where you got to.'];

                continue;
            }
            $this->safeRun($call, approved: false);
            $this->save();
        }

        return true;
    }

    /** @param  array{id: string, name: string, arguments: array<string, mixed>}  $call */
    private function safeRun(array $call, bool $approved): void
    {
        $before = count($this->chat['messages']);
        try {
            $this->runCall($call, $approved);
        } catch (Throwable $e) {
            report($e);
            $answered = collect(array_slice($this->chat['messages'], $before))->contains(fn (array $m) => $m['role'] === 'tool' && $m['id'] === $call['id']);
            if (! $answered) {
                $this->chat['messages'][] = ['role' => 'tool', 'id' => $call['id'], 'name' => $call['name'], 'content' => 'Error: something went wrong running this. It may or may not have happened: check before trying again.'];
            }
            ($this->emit)(['type' => 'result', 'id' => $call['id'], 'ok' => false, 'state' => 'failed', 'summary' => 'Something went wrong']);
        }
    }

    /** @param  array{id: string, name: string, arguments: array<string, mixed>}  $call */
    private function runCall(array $call, bool $approved): void
    {
        $this->toolCalls++;
        $tool = $this->registry->find($call['name']);
        if ($tool !== null && $tool->scope() !== Scopes::READ && ! in_array($tool->name(), self::NO_APPROVAL, true)) {
            $this->changed = true;
        }
        $title = $tool?->title() ?? self::virtualTitle($call['name']);
        if (! $approved) {
            ($this->emit)(['type' => 'step', 'id' => $call['id'], 'tool' => $call['name'], 'title' => $title, 'detail' => self::detail($call['arguments'])]);
        } else {
            ($this->emit)(['type' => 'running', 'id' => $call['id']]);
        }

        [$ok, $data] = match (true) {
            $call['name'] === 'load_tools' => $this->loadTools($call['arguments']),
            $call['name'] === 'investigate_problem' => $this->investigate($call['arguments']),
            $tool === null => [false, "There’s no tool called {$call['name']}."],
            ! $this->usable($tool) => [false, $this->registry->denial($tool, $this->context) ?? 'Ask AI can’t use that tool. Do it in the dashboard.'],
            default => $this->server->execute($tool, $call['arguments'], $this->context),
        };

        $outcome = null;
        if ($ok && is_array($data) && $tool !== null && $tool->scope() !== Scopes::READ && ($jobId = self::jobId($data)) !== null) {
            $outcome = $this->follow($call['id'], $jobId);
        }

        $text = is_string($data) ? $data : (json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        if ($outcome !== null) {
            $ok = $outcome['ok'];
            $text = Str::limit($text, 2_500, ' …')."\n\nServerOS followed the job it started until it finished: ".(json_encode($outcome['job'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
        }
        $this->chat['messages'][] = [
            'role' => 'tool',
            'id' => $call['id'],
            'name' => $call['name'],
            'content' => ($ok ? '' : 'Error: ').Str::limit($text, self::RESULT_CHARS, ' …(cut short)'),
        ];

        $url = $ok && is_array($data) ? self::pageUrl($data) : null;
        $follow = $url !== null && ($tool === null || $tool->scope() !== Scopes::READ || in_array($tool->name(), self::SHOWS_PAGE, true));
        ($this->emit)([
            'type' => 'result',
            'id' => $call['id'],
            'ok' => $ok,
            'state' => $ok ? 'done' : 'failed',
            'summary' => $outcome !== null ? $outcome['summary'] : ($ok ? self::summary($data) : Str::limit($text, 220)),
            'url' => $url,
        ]);
        if ($follow) {
            ($this->emit)(['type' => 'navigate', 'url' => $url, 'target' => $call['name']]);
        }
    }

    /** @return array{ok: bool, summary: string, job: array<string, mixed>}|null */
    private function follow(string $callId, string $jobId): ?array
    {
        try {
            $job = ServiceWork::job($this->context, $jobId);
        } catch (ToolError) {
            return null;
        }

        $deadline = min(microtime(true) + self::FOLLOW_SECONDS, $this->deadline);
        $shown = null;
        $sent = microtime(true);
        while (! in_array($job->state, McpContext::FINISHED, true) && microtime(true) < $deadline && ! connection_aborted()) {
            $phase = Str::ucfirst(str_replace('_', ' ', (string) ($job->phase ?: $job->state)));
            $log = array_values(array_filter(array_map(fn (string $line) => trim($line), $job->log ?? [])));
            $now = $phase.'…'.($log !== [] ? ' '.Str::limit((string) end($log), 90) : '');
            if ($now !== $shown) {
                ($this->emit)(['type' => 'result', 'id' => $callId, 'ok' => true, 'state' => 'running', 'summary' => $now]);
                $shown = $now;
                $sent = microtime(true);
            } elseif (microtime(true) - $sent >= self::KEEPALIVE_SECONDS) {
                ($this->emit)(['type' => 'ping']);
                $sent = microtime(true);
            }
            sleep(2);
            $job->refresh();
        }

        $finished = in_array($job->state, McpContext::FINISHED, true);
        $error = is_array($job->error) ? ($job->error['message'] ?? null) : null;

        return [
            'ok' => ! $finished || $job->state === 'succeeded',
            'summary' => match (true) {
                ! $finished => 'Still going: it carries on in the background',
                $job->state === 'succeeded' => 'Finished',
                default => Str::limit('Failed'.(is_string($error) ? ': '.$error : ''), 220),
            },
            'job' => self::withoutNoise(Present::job($job, $job->state === 'succeeded' ? 5 : 40)),
        ];
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private static function withoutNoise(array $job): array
    {
        $noise = fn (mixed $line) => is_string($line) && preg_match('/^\s*(\[[^\]]+\]\s*)?PHP (Deprecated|Notice|Warning):|^DEPRECATED: The legacy builder|^\s*(Install the buildx|https:\/\/docs\.docker\.com\/go\/buildx)/', $line) === 1;
        $clean = fn (array $lines) => array_values(array_slice(array_filter($lines, fn ($line) => ! $noise($line)), -30));

        if (is_array($job['log'] ?? null)) {
            $job['log'] = $clean($job['log']);
        }
        if (is_array($job['error']['output_tail'] ?? null)) {
            $job['error']['output_tail'] = $clean($job['error']['output_tail']);
            $job['error']['note'] = 'output_tail is the end of what the app or build printed before it failed (warnings left out). A release that failed its health check has been removed, so there are no logs of it to read anywhere else: the cause is here.';
        }

        return $job;
    }

    /** @param  array<mixed>  $data */
    private static function jobId(array $data): ?string
    {
        $id = $data['jobId'] ?? (is_array($data['job'] ?? null) ? ($data['job']['id'] ?? null) : null);

        return is_string($id) && $id !== '' ? $id : null;
    }

    private static function claimsAction(string $text): bool
    {
        return preg_match("/\\b(I['’]ll (deploy|create|set up|install|restart|start|configure|add|run)|I will (deploy|create|set up|install|restart|start|configure|add|run)|(deploy|deployment|install|workflow|rollout)\\s+(has\\s+)?(started|queued|is running|is under way)|is being (deployed|installed|created)|I['’]ve (deployed|started|created|installed|set up|queued))/iu", $text) === 1;
    }

    private function namesTool(string $text): ?string
    {
        foreach ($this->toolDefinitions() as $tool) {
            if (preg_match('/\b'.preg_quote($tool['name'], '/').'\b/', $text) === 1) {
                return $tool['name'];
            }
        }

        return null;
    }

    private static function asksLeave(string $text): bool
    {
        return preg_match("/(shall I|should I (go ahead|proceed|continue)|let me know if you(['’]d| would) like me to|would you like me to (proceed|go ahead|continue|do)|do you want me to (proceed|go ahead|continue)|want me to proceed)/iu", $text) === 1;
    }

    private function needsApproval(Tool $tool): bool
    {
        if ($tool->scope() === Scopes::READ || in_array($tool->name(), self::NO_APPROVAL, true)) {
            return false;
        }

        return $tool->destructive() || in_array($tool->name(), self::ALWAYS_ASK, true) || ! $this->chat['allow_changes'];
    }

    private function usable(Tool $tool): bool
    {
        return $tool->scope() !== Scopes::COMMANDS && $this->registry->denial($tool, $this->context) === null;
    }

    /**
     * @param  array{id: string, name: string, arguments: array<string, mixed>}  $call
     * @return array<string, mixed>
     */
    private function approval(array $call, Tool $tool): array
    {
        $url = null;
        if (is_string($call['arguments']['machine'] ?? null) || is_int($call['arguments']['machine'] ?? null)) {
            try {
                $url = $this->context->url('/machines/'.$this->context->machine($call['arguments']['machine'])->id);
            } catch (ToolError) {
            }
        }

        return [
            'type' => 'approval',
            'id' => $call['id'],
            'tool' => $tool->name(),
            'title' => $tool->title(),
            'detail' => self::detail($call['arguments']),
            'fields' => self::fields($call['arguments']),
            'destructive' => $tool->destructive(),
            'alwaysAsk' => $tool->destructive() || in_array($tool->name(), self::ALWAYS_ASK, true),
            'url' => $url,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: array<string, mixed>|string}
     */
    private function loadTools(array $arguments): array
    {
        $names = array_values(array_filter(array_map('strval', (array) ($arguments['names'] ?? [])), fn ($n) => $n !== ''));
        $loaded = $missing = [];
        foreach (array_slice($names, 0, 8) as $name) {
            $tool = $this->registry->find($name);
            if ($tool === null || ! $this->usable($tool)) {
                $missing[] = $name;

                continue;
            }
            $loaded[] = $name;
        }
        $this->chat['loaded'] = array_slice(array_values(array_unique([...$loaded, ...$this->chat['loaded']])), 0, self::MAX_LOADED);

        return [$loaded !== [], array_filter([
            'loaded' => $loaded,
            'not_available' => $missing ?: null,
            'next' => $loaded !== [] ? 'They’re yours from your next step: call them.' : 'None of those can be used here. Pick names from the list.',
        ])];
    }

    private function isLoaded(Tool $tool): bool
    {
        return in_array($tool->name(), [...self::CORE, ...$this->chat['loaded']], true);
    }

    private function loadTool(Tool $tool): void
    {
        $this->chat['loaded'] = array_slice(array_values(array_unique([$tool->name(), ...$this->chat['loaded']])), 0, self::MAX_LOADED);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: array<string, mixed>|string}
     */
    private function investigate(array $arguments): array
    {
        $workspace = $this->context->workspace;
        try {
            if (! $workspace->hasAutomations()) {
                throw new ToolError('The server agent comes with Pro. Tell the person, and look at the logs yourself instead.');
            }
            $this->context->require(WorkspacePermission::ManageMachines);
            $machine = $this->context->machine($arguments['machine'] ?? '');
            $service = filled($arguments['service'] ?? null) ? $this->context->service($machine, $arguments['service']) : null;
            if (($why = FixAllowance::refuse($workspace)) !== null) {
                throw new ToolError($why);
            }
        } catch (ToolError $e) {
            return [false, $e->getMessage()];
        }

        $fix = AgentFix::query()->create([
            'workspace_id' => $workspace->id,
            'machine_id' => $machine->id,
            'service_key' => $service['key'] ?? null,
            'service_name' => $service['name'] ?? null,
            'user_id' => $this->context->user->id,
            'source' => 'manual',
            'note' => filled($arguments['note'] ?? null) ? mb_substr((string) $arguments['note'], 0, 1000) : null,
            'state' => 'investigating',
        ]);
        Activity::record($workspace, 'fix.start', $service['name'] ?? $machine->name, $machine, $this->context->user, ['fix' => $fix->id, 'via' => 'assistant', 'client' => 'Ask AI']);
        DiagnoseFix::dispatch($fix->id);

        return [true, [
            'fix' => $fix->id,
            'state' => 'investigating',
            'url' => $this->context->url('/fixes?fix='.$fix->id),
            'next' => 'The server agent is looking into it now; it takes a minute. Its diagnosis and any steps to approve show on the Fixes page. Tell the person that.',
        ]];
    }

    /** @return list<array{name: string, description: string, schema: array<string, mixed>}> */
    private function toolDefinitions(): array
    {
        $tools = [];
        foreach (array_unique([...self::CORE, ...$this->chat['loaded']]) as $name) {
            $tool = $this->registry->find($name);
            if ($tool !== null && $this->usable($tool)) {
                $tools[] = ['name' => $tool->name(), 'description' => $tool->description(), 'schema' => $tool->inputSchema()];
            }
        }

        $tools[] = [
            'name' => 'load_tools',
            'description' => 'Load more tools by name, from the list of every tool in your instructions. They can be called from your next step. Load only what you need, up to 8 at a time.',
            'schema' => ['type' => 'object', 'properties' => ['names' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8, 'description' => 'Tool names, exactly as listed.']], 'required' => ['names'], 'additionalProperties' => false],
        ];
        $tools[] = [
            'name' => 'investigate_problem',
            'description' => 'Ask ServerOS’s server agent to look into a problem on a machine or one of its services: it reads the state, logs and failed jobs, writes a diagnosis, and proposes fixes the person approves on the Fixes page. Use it when something is broken and the cause isn’t obvious. Comes with Pro; counts against the hour’s investigations.',
            'schema' => ['type' => 'object', 'properties' => [
                'machine' => ['type' => 'string', 'description' => 'The machine: its id or name.'],
                'service' => ['type' => 'string', 'description' => 'The service, if it’s about one: its key or name.'],
                'note' => ['type' => 'string', 'description' => 'What the person says is wrong, in their words.', 'maxLength' => 1000],
            ], 'required' => ['machine'], 'additionalProperties' => false],
        ];

        return $tools;
    }

    private function instructions(): string
    {
        $workspace = $this->context->workspace;
        $user = $this->context->user;
        $actions = app(ProjectActions::class);
        $machines = $workspace->machines()->orderBy('name')->limit(25)->get()
            ->map(function (Machine $m) use ($actions) {
                $deployed = $actions->deployed($m)->take(15)->map(fn (array $d) => "{$d['name']} ({$d['kind']})")->implode(', ');

                return "- #{$m->id} {$m->name} ({$m->environment->value}, {$m->services_count} services".($m->last_seen_at?->gt(now()->subMinutes(5)) ? '' : ', offline').')'
                    .($deployed !== '' ? "\n  ServerOS deployed: {$deployed}" : '');
            })
            ->implode("\n") ?: '- none yet: they need to connect one first (Machines › Connect machine).';
        $plan = $workspace->effectivePlan()?->label() ?? 'no plan';
        $firstName = Str::before((string) $user->name, ' ');
        $path = $this->chat['path'] ?? '/';
        $page = '';
        if (preg_match('#^/projects/([a-z0-9]{6,20})#', (string) $path, $m) === 1
            && ($project = $workspace->projects()->where('uid', $m[1])->first()) !== null) {
            $project->load(['nodes.machine', 'links.app', 'links.resource']);
            $parts = $project->nodes->map(fn (ProjectNode $n) => "{$n->name} ({$n->kind}, on {$n->machine->name}, ".(ProjectActions::status($n->machine, $n->name) ?? 'gone').')')->implode('; ') ?: 'nothing yet';
            $links = $project->links->map(fn (ProjectLink $l) => "{$l->app->name} → {$l->resource->name} ({$l->state})")->implode('; ') ?: 'none';
            $page = "\n- That's the project {$project->name} (id {$project->uid}). Its parts: {$parts}. Connections: {$links}.";
        }
        $role = $this->context->can(WorkspacePermission::ManageMachines) ? 'They can make changes.' : 'Their role can only look, not change things: say so if they ask for a change.';

        $groups = [];
        foreach ((array) config('mcp.groups', []) as $group) {
            $names = [];
            foreach ($group::tools() as $class) {
                $tool = $this->registry->find(app($class)->name());
                if ($tool !== null && $this->usable($tool) && ! in_array($tool->name(), self::CORE, true)) {
                    $names[] = $tool->name();
                }
            }
            if ($names !== []) {
                $groups[] = Str::of(class_basename($group))->beforeLast('Tools').': '.implode(', ', $names);
            }
        }
        $catalogue = implode("\n", $groups);

        $asked = array_values(array_filter($this->chat['messages'], fn (array $m) => $m['role'] === 'user'));
        $question = implode("\n", array_column(array_slice($asked, -2), 'content'));
        $budget = self::DOCS_CHARS;
        $docs = collect($this->docs->search($question, 2))->map(function (array $page) use (&$budget) {
            $body = Str::limit($page['body'], max(0, $budget), "\n[…]");
            $budget -= strlen($body);

            return "### {$page['title']}\nURL: {$page['url']}\n\n{$body}";
        })->filter()->implode("\n\n---\n\n");
        $docs = $docs === '' ? '' : "\n\nDocs that match the question:\n\n{$docs}";
        $now = now()->utc()->format('D j M Y, H:i');

        return <<<PROMPT
You are Ask AI, the assistant inside the ServerOS panel. ServerOS is one panel for the Linux servers people already rent: a small daemon connects each machine, finds what's running, and ServerOS handles deploys, domains, backups, monitors, logs, alerts and workflows.

You answer questions, and you do things in the workspace with tools: the same tools AI apps use over ServerOS's MCP server. The person watches you work. Each tool you run shows as a step, and the panel follows you to the page you're working on.

How to work:
- Never say something doesn't exist, or isn't there, without having looked it up with a tool or seeing it below.
- Look before you act, and use the ids and keys tools return. Never invent ids, templates, features or results.
- When they name something ("the shop app on web-1", "its Postgres"), they mean what's already there: find it (list_deploys, list_services, get_project) and use it. Never deploy a new copy, or code from a different repository than the one running, unless they ask for exactly that.
- Finish everything they asked for. Don't hand a step back to them ("use Connect on the project page") when you have a tool that does it: call it.
- Prefer the smallest change that fixes it. Never stop, remove or replace something that's running, or anything that can't be undone, as a step towards something else, unless the person asked for exactly that: explain the options and ask first.
- Changes need the person's approval, and the panel asks for it: when you call a tool that changes something, they see what it'll do with an Approve button. So don't ask "shall I go ahead?" in words. When you know what to do, call the tool. Ask in words only for what you can't look up or sensibly default: which of several machines, a domain name, a repository.
- If they decline a change, don't try it again; ask what they'd like instead.
- Never say something has started, is running or is done unless a tool in this conversation did it and said so. Don't describe what you're about to do and stop: call the tool in the same reply.
- You start with a few core tools. Every other tool is listed by name below: call load_tools with the names you need and you can use them from your next step.
- Deploying ("I need a web service running X, Y and Z"): list_templates shows what ServerOS deploys in one click. Load deploy_template and deploy each piece, databases and caches first, then the app, wired to them. To wire an app to a database or cache ServerOS deployed, get its settings with get_connection and pass them in extra_env: apps reach it through the Docker bridge address it gives, never a container name. Never show or repeat a password. For their own code from a GitHub repository, run detect_repository first: it says which app template builds it, and for PHP apps the PHP version (php) and health check (health_path) to pass to deploy_template, which old apps need or they crash on start. A repository with its own Dockerfile: deploy_from_git. With one machine, use it; with several, use the one they named or ask. If something they want has no template and they have no repository for it, say so plainly.
- Projects group an app with the databases and caches it uses and wire their settings into it, across machines too, and can copy Production into Staging later. When they ask for a project, or to "set up" an app with its database as one thing: start with get_project (or create_project) and list_deploys. Put what's already deployed on it with add_to_project (the app, and the databases and caches it uses), then connect_project_app for each. add_project_resource deploys a brand-new, empty one: only when they want a new one. get_project shows how it went; a connection can wait a minute for its resource or network to come up.
- Work on servers runs as jobs. When a change you make starts one, ServerOS watches it until it finishes and adds how it ended (its error and the end of its log) to the tool's answer, so you don't need get_job for it.
- When something fails, don't stop at the error message: work out why. Read the job's error and log lines, the service's logs (get_service_logs), earlier deploys (list_deploys) and the machine. Then fix what you can with your tools (install a missing package, change a setting, deploy again) and try again. Give up and hand it to the person only when it needs something only they can do (a change to their code, a secret, a DNS record), and then say exactly what and why, quoting the line that shows it. Don't retry the same thing unchanged more than once.
- Before telling the person something is the service they asked about, check it is: its name, image or app, not just a port. Never make up addresses or URLs; use only those tools return, and say so if there isn't one (an app with no domain is only reachable on the machine).
- Workflows and automations (CI/CD, deploy on every push to GitHub, nightly backups, alerts to Slack): list_workflow_templates first, then load get_workflow_reference and create_workflow. Start from a template when one fits. The coding agent templates open pull requests on GitHub. A workflow that needs GitHub needs the workspace's GitHub connected in Settings.
- A machine missing something a deploy needs (git for a deploy from a repository, unzip and so on): load install_packages and install it, then deploy again. It installs from the machine's own repositories only; there's no shell and no SSH, so don't offer to run other commands on the machine.
- A database or cache the internet can reach: load get_firewall, close_port and rescan_machine. get_firewall says how each port is exposed. "open" (the firewall lets it in): close_port, unless something outside the machine really needs it. "docker" (Docker publishes it, which gets in before the firewall): closing the port won't help; it has to be published on 127.0.0.1 or the Docker bridge instead. A stack ServerOS deployed from a template already is, so redeploying it fixes it. Anything else has to be re-created with a different port mapping, and data that isn't in a volume goes with the old container: don't stop, remove or replace it yourself. Explain that, give the exact change (in its compose file, "127.0.0.1:6380:6379" instead of "6380:6379"), and ask how they'd like to go ahead. Don't say its data will be kept unless a tool showed it's in a named volume. There's no way yet to allow only certain addresses, so say so if they ask. Afterwards rescan_machine and read get_firewall again, and only say it's fixed if it shows the port isn't reachable any more.
- Something broken: look at the service and its logs. For a real diagnosis, call investigate_problem: the server agent looks into it and proposes fixes they approve on the Fixes page.
- If a tool fails, say what it said. If you're out of AI tool calls or messages, say so and where to get more (Billing).

How to answer:
- Short and plain: a sentence or two, or a short list. Once you've done something, say what you did and where to see it.
- Name panel pages and buttons in bold. Link docs pages only with the docs URLs given here, if any. No headings, no filler, no em dashes.
- Never paste raw JSON, job ids or tool output: say it in words. The steps already show what you ran, each with a link to its page.
- Passwords and keys a deploy generated are shown only once: give them in a code span and say to keep them somewhere safe.

About the person:
- Name: {$firstName}. {$role}
- Workspace: {$workspace->name} on the {$plan} plan
- They're looking at: {$path}{$page}
- It's {$now} UTC.
- Machines:
{$machines}

Every other tool, by group (load_tools to use them):
{$catalogue}{$docs}
PROMPT;
    }

    private function trim(): void
    {
        $messages = $this->chat['messages'];
        if (count($messages) > self::HISTORY) {
            $messages = array_slice($messages, -self::HISTORY);
            while ($messages !== [] && $messages[0]['role'] !== 'user') {
                array_shift($messages);
            }
        }

        $lastUser = null;
        foreach ($messages as $i => $m) {
            if ($m['role'] === 'user') {
                $lastUser = $i;
            }
        }
        foreach ($messages as $i => $m) {
            if ($m['role'] === 'tool' && $lastUser !== null && $i < $lastUser) {
                $messages[$i]['content'] = Str::limit($m['content'], self::OLD_RESULT_CHARS, ' …(cut short)');
            }
        }

        $this->chat['messages'] = array_values($messages);
    }

    private function save(): void
    {
        Cache::put(self::cacheKey($this->chat['id']), $this->chat, now()->addHours(self::TTL_HOURS));
    }

    private static function cacheKey(string $id): string
    {
        return "assistant:chat:{$id}";
    }

    private static function virtualTitle(string $name): string
    {
        return match ($name) {
            'load_tools' => 'Get tools ready',
            'investigate_problem' => 'Ask the server agent',
            default => Str::headline($name),
        };
    }

    /** @param  array<string, mixed>  $arguments */
    private static function detail(array $arguments): string
    {
        $values = [];
        foreach (McpServer::redact($arguments) as $key => $value) {
            if (in_array($key, ['wait', 'log_lines', 'limit', 'confirm'], true) || $value === '[hidden]') {
                continue;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $values[] = is_bool($value) ? ($value ? $key : "no {$key}") : (string) $value;
            } elseif (is_array($value) && $value !== [] && array_is_list($value) && is_scalar($value[0])) {
                $values[] = implode(', ', array_slice(array_map('strval', $value), 0, 6));
            }
        }

        return Str::limit(implode(' · ', array_slice($values, 0, 4)), 120);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array{label: string, value: string}>
     */
    private static function fields(array $arguments): array
    {
        $rows = [];
        foreach ($arguments as $key => $value) {
            if (in_array($key, ['wait', 'confirm'], true)) {
                continue;
            }
            $text = match (true) {
                is_bool($value) => $value ? 'Yes' : 'No',
                $value === null => 'None',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
            $rows[] = ['label' => Str::ucfirst(str_replace('_', ' ', (string) $key)), 'value' => $text];
        }

        return $rows;
    }

    /** @param  array<mixed>  $data */
    private static function pageUrl(array $data): ?string
    {
        foreach (['deploymentsUrl', 'pageUrl', 'url'] as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && ! array_is_list($value)) {
                foreach (['deploymentsUrl', 'pageUrl', 'url'] as $key) {
                    if (is_string($value[$key] ?? null)) {
                        return $value[$key];
                    }
                }
            }
        }

        return null;
    }

    /** @param  array<mixed>|string  $data */
    private static function summary(array|string $data): string
    {
        if (is_string($data)) {
            return Str::limit($data, 120);
        }
        if (array_is_list($data)) {
            return count($data).' found';
        }
        $state = $data['job']['state'] ?? $data['state'] ?? null;
        if (is_string($state)) {
            return Str::ucfirst(str_replace('_', ' ', $state));
        }
        foreach (['items', 'machines', 'services', 'templates', 'workflows', 'monitors', 'results', 'jobs', 'deploys'] as $key) {
            if (is_array($data[$key] ?? null) && array_is_list($data[$key])) {
                return count($data[$key]).' found';
            }
        }

        return 'Done';
    }
}
