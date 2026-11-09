<?php

namespace App\Mcp;

use App\Models\McpToolCall;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditCoverage;
use App\Support\Extensions\Extensions;
use App\Support\Mcp\McpUsage;
use App\Support\Stats\Visits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The Model Context Protocol over JSON-RPC: initialize, ping, tools/list
 * and tools/call. It keeps no session: every request carries its own
 * credentials, so any request can come first.
 */
final class McpServer
{
    /** Newest first; the first is offered when a client asks for another. */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const NAME = 'serveros';

    public const VERSION = '1.0.0';

    /** Argument names whose values aren't written to the audit log. */
    private const SECRET_ARGUMENTS = ['env', 'extra_env', 'set', 'password', 'secret', 'token', 'authorization', 'headers', 'access_key', 'secret_key'];

    /** Any argument named like a secret, at any depth: DB_PASSWORD, GITHUB_TOKEN, API_KEY. */
    private const SECRET_NAME = '/(pass(word|wd)?|secret|token|api_?key|private_?key|credential|auth)/i';

    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * Answer one JSON-RPC message, or a batch. Notifications get no answer
     * (null).
     *
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public function handle(mixed $payload, McpContext $context): ?array
    {
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $answers = array_values(array_filter(array_map(fn ($message) => $this->message($message, $context), $payload)));

            return $answers === [] ? null : $answers;
        }

        return $this->message($payload, $context);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function message(mixed $message, McpContext $context): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            // A response or something malformed. Responses to us don't happen: we never ask the client anything.
            return is_array($message) && (isset($message['result']) || isset($message['error']))
                ? null
                : self::error($message['id'] ?? null, -32600, 'Invalid request.');
        }

        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($isNotification) {
            return null;
        }

        return match ($message['method']) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, new \stdClass),
            'tools/list' => self::result($id, ['tools' => array_values(array_map(fn (Tool $t) => $t->definition(), $this->tools->for($context)))]),
            'tools/call' => $this->call($id, $params, $context),
            // Not offered, but harmless to answer.
            'resources/list' => self::result($id, ['resources' => []]),
            'resources/templates/list' => self::result($id, ['resourceTemplates' => []]),
            'prompts/list' => self::result($id, ['prompts' => []]),
            default => self::error($id, -32601, "Method not found: {$message['method']}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : null;

        return [
            'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::NAME, 'title' => 'ServerOS', 'version' => self::VERSION],
            'instructions' => self::instructions(),
        ];
    }

    public static function instructions(): string
    {
        return <<<'TEXT'
            ServerOS manages the person's real servers: machines, the services on them, deploys, monitors, status pages, workflows, backups and logs. This MCP server is EXPERIMENTAL: tools can fail halfway or do the wrong thing, so:
            - Start with get_workspace to see what's here and what this connection may do.
            - Before anything that deletes, stops, removes, reboots or deploys, say exactly what you'll do and get the person's go-ahead. Tools that can't be undone need a confirm argument.
            - Refer to machines and services by the ids and keys tools return; names work too when they're unique.
            - Work on servers runs as jobs. Tools that start one return its id and state; call get_job to follow it until it's finished, and report failures as they are.
            - Everything you do is recorded in the workspace's activity, credited to the person, as done through MCP.
            - Each tool call that works counts as one of the workspace's AI tool calls for the month; get_workspace says how many are left. Don't poll more than you need to.
            TEXT;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params, McpContext $context): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $tool = $this->tools->find($name);
        if ($tool === null) {
            return self::error($id, -32602, "Unknown tool: {$name}");
        }

        [$ok, $data] = $this->execute($tool, $arguments, $context);

        return self::result($id, self::content($ok ? $data : ['error' => $data], ! $ok));
    }

    /**
     * Run one tool for a connection: checked against its scopes and the
     * person's role, counted against the month's AI tool calls (given back
     * if it fails), audited and logged. The panel's assistant runs tools
     * through here too; its lookups don't count, only its changes.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: true, 1: array<string, mixed>|list<mixed>|string}|array{0: false, 1: string}
     */
    public function execute(Tool $tool, array $arguments, McpContext $context): array
    {
        $name = $tool->name();
        $started = hrtime(true);
        $context->startCall($name);
        $denied = $this->tools->denial($tool, $context);
        $claimed = null;
        try {
            if ($denied !== null) {
                throw new ToolError($denied);
            }
            // The call is counted before the tool runs, so calls at the same
            // time can't go past the limit; one that fails is given back.
            if (! ($context->via === 'assistant' && $tool->scope() === Scopes::READ)) {
                $claimed = McpUsage::claim($context->workspace) ?? throw new ToolError(self::outOfCalls($context));
            }
            AuditContext::forgetRecorded();
            // Everything the tool does carries this call: activity, and the
            // server jobs it queues (and so the daemon's actions.log).
            $data = $context->within(fn () => $tool->handle($context, $arguments));
            AuditCoverage::afterTool($tool);
            $this->log($context, $name, $arguments, true, null, $started);

            return [true, $data];
        } catch (ToolError $e) {
            $message = $e->getMessage();
        } catch (ValidationException $e) {
            $message = 'Invalid arguments: '.implode(' ', Arr::flatten($e->errors()));
        } catch (ModelNotFoundException) {
            $message = 'That doesn’t exist in this workspace.';
        } catch (AuthorizationException $e) {
            $message = 'Your role doesn’t allow that.';
        } catch (HttpExceptionInterface $e) {
            $message = match ($e->getStatusCode()) {
                404 => 'That doesn’t exist in this workspace.',
                403 => 'Your role doesn’t allow that.',
                default => $e->getMessage() ?: 'That didn’t work.',
            };
        } catch (Throwable $e) {
            report($e);
            $message = 'Something went wrong running this tool. It’s been logged; try again, or do it in the ServerOS dashboard.';
        }

        if ($claimed !== null) {
            $this->giveBack($context, $claimed);
        }
        $this->log($context, $name, $arguments, false, $message, $started);

        return [false, $message];
    }

    /** Why a call can't run: the month's AI tool calls are used up. */
    private static function outOfCalls(McpContext $context): string
    {
        $workspace = $context->workspace;
        // Where to get more, if anywhere.
        $upgrade = app(Extensions::class)->upgradeLink();
        $where = $upgrade === null ? '.' : " in {$upgrade->label}: ".$context->url($upgrade->path($workspace));
        if (! $workspace->isActive() && $workspace->mcp_call_limit_override === null) {
            return "{$workspace->name} has no plan, so AI apps can't use it. An owner can choose one{$where}";
        }

        $limit = number_format((int) McpUsage::limit($workspace));
        $resets = McpUsage::resetsAt()->format('j F');

        return "{$workspace->name} has used all {$limit} of its AI tool calls this month. They reset on {$resets}. "
            ."An owner can add more calls or upgrade the plan{$where}";
    }

    /** A claimed call back to the month's allowance: the tool didn't work. */
    private function giveBack(McpContext $context, string $period): void
    {
        try {
            McpUsage::release($context->workspace, $period);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A tool's answer as MCP content: the data as JSON text for every
     * client, and as structured content for those that read it.
     *
     * @param  array<string, mixed>|list<mixed>|string  $data
     * @return array<string, mixed>
     */
    public static function content(array|string $data, bool $isError): array
    {
        if (is_string($data)) {
            return ['content' => [['type' => 'text', 'text' => $data]], 'isError' => $isError];
        }

        $structured = array_is_list($data) && $data !== [] ? ['items' => $data] : ($data === [] ? new \stdClass : $data);

        return [
            'content' => [['type' => 'text', 'text' => json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}']],
            'structuredContent' => $structured,
            'isError' => $isError,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function log(McpContext $context, string $tool, array $arguments, bool $ok, ?string $error, int|float $started): void
    {
        try {
            McpToolCall::query()->create([
                'workspace_id' => $context->workspace->id,
                'user_id' => $context->user->id,
                'mcp_token_id' => $context->token?->id,
                'call_id' => $context->callId,
                'tool' => mb_substr($tool, 0, 64),
                'arguments' => self::redact($arguments),
                'ok' => $ok,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'duration_ms' => (int) min(4_000_000_000, (hrtime(true) - $started) / 1e6),
                'created_at' => now(),
            ]);
            // An AI app working here counts as the person using it that day.
            if ($ok) {
                Visits::record($context->workspace->id, $context->user->id, 'mcp');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Arguments as the audit log keeps them: secrets blanked, long text cut.
     * Nested lists come through here too, so keys can be ints.
     *
     * @param  array<mixed>  $arguments
     * @return array<mixed>
     */
    public static function redact(array $arguments, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($arguments, 0, 40, true) as $key => $value) {
            if (is_string($key) && (in_array(strtolower($key), self::SECRET_ARGUMENTS, true) || preg_match(self::SECRET_NAME, $key) === 1)) {
                $out[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $out[$key] = $depth >= 3 ? '[…]' : self::redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = Str::limit($value, 500);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
