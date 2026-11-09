<?php

namespace App\Api;

use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Mcp\ToolRegistry;
use App\Models\McpToolCall;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditCoverage;
use App\Support\Stats\Visits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs one tool for an API request: the connection's scopes and the
 * person's role are checked as MCP checks them, the answer comes back as
 * { "data": ... } or { "error": { "type", "message" } } with an HTTP
 * status that says what kind of answer it is, and the call is logged.
 * API calls don't use the workspace's AI tool calls; they're limited per
 * minute instead (AuthenticateApiKey).
 */
final class ApiRunner
{
    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function run(McpContext $context, Tool $tool, array $arguments): array
    {
        $started = hrtime(true);
        $context->startCall($tool->name());
        [$status, $body] = $this->attempt($context, $tool, $arguments);
        $this->log($context, $tool->name(), $arguments, $status, $body['error']['message'] ?? null, $started);

        return [$status, $body];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function attempt(McpContext $context, Tool $tool, array $arguments): array
    {
        if (($denied = $this->tools->denial($tool, $context)) !== null) {
            return self::error(403, 'forbidden', $denied);
        }

        try {
            // As McpServer::call does: a tool that changes something must
            // record it, and the tests fail when one doesn't.
            AuditContext::forgetRecorded();
            $data = $context->within(fn () => $tool->handle($context, $arguments));
            AuditCoverage::afterTool($tool);

            return [200, ['data' => is_string($data) ? ['text' => $data] : ($data === [] ? new \stdClass : $data)]];
        } catch (ToolError $e) {
            $message = $e->getMessage();

            return str_starts_with($message, 'Invalid arguments')
                ? self::error(422, 'invalid_arguments', $message)
                : self::error(422, 'unprocessable', $message);
        } catch (ValidationException $e) {
            return self::error(422, 'invalid_arguments', 'Invalid arguments: '.implode(' ', Arr::flatten($e->errors())));
        } catch (ModelNotFoundException) {
            return self::error(404, 'not_found', 'That doesn’t exist in this workspace.');
        } catch (AuthorizationException) {
            return self::error(403, 'forbidden', 'Your role doesn’t allow that.');
        } catch (HttpExceptionInterface $e) {
            return match ($e->getStatusCode()) {
                404 => self::error(404, 'not_found', 'That doesn’t exist in this workspace.'),
                403 => self::error(403, 'forbidden', 'Your role doesn’t allow that.'),
                default => self::error($e->getStatusCode(), 'error', $e->getMessage() ?: 'That didn’t work.'),
            };
        } catch (Throwable $e) {
            report($e);

            return self::error(500, 'server_error', 'Something went wrong. It’s been logged; try again, or do it in the ServerOS dashboard.');
        }
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function error(int $status, string $type, string $message): array
    {
        return [$status, ['error' => ['type' => $type, 'message' => $message]]];
    }

    /**
     * Query strings and path segments are text: make them the types the
     * tool's schema asks for, so ?limit=5&managed=true work.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function coerce(Tool $tool, array $values): array
    {
        $properties = (array) json_decode((string) json_encode($tool->inputSchema()['properties'] ?? []), true);

        foreach ($values as $key => $value) {
            $type = $properties[$key]['type'] ?? null;
            if (! is_string($value) || $type === null) {
                continue;
            }
            $values[$key] = match ($type) {
                'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
                'number' => is_numeric($value) ? $value + 0 : $value,
                'boolean' => match (strtolower($value)) {
                    'true', '1', 'yes' => true,
                    'false', '0', 'no' => false,
                    default => $value,
                },
                'array' => array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v) => $v !== '')),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function log(McpContext $context, string $tool, array $arguments, int $status, ?string $error, int|float $started): void
    {
        try {
            McpToolCall::query()->create([
                'workspace_id' => $context->workspace->id,
                'user_id' => $context->user->id,
                'mcp_token_id' => $context->token?->id,
                'via' => 'api',
                'call_id' => $context->callId,
                'tool' => mb_substr($tool, 0, 64),
                'arguments' => McpServer::redact($arguments),
                'ok' => $status < 400,
                'status' => $status,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'duration_ms' => (int) min(4_000_000_000, (hrtime(true) - $started) / 1e6),
                'created_at' => now(),
            ]);
            if ($status < 400) {
                Visits::record($context->workspace->id, $context->user->id, 'api');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
