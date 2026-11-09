<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use App\Support\Assistant\AssistantAgent;
use App\Support\Assistant\AssistantAllowance;
use App\Support\Audit\NotAudited;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ask AI in the panel: answers from the docs and does things in the
 * workspace with the MCP tools, streaming each step back as a line of JSON
 * (AssistantAgent says which events). Changes wait for the person's
 * approval (decide). Everyone in the workspace can ask; what it may change
 * follows their role.
 */
class AssistantController extends Controller
{
    /** Longer than any request can work (AssistantAgent::BUDGET_SECONDS, plus a model call). */
    public const LOCK_SECONDS = 1200;

    #[NotAudited('A message to Ask AI; the tools it runs are audited themselves')]
    public function __invoke(Request $request, Workspace $workspace, AssistantAgent $agent): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'chat' => ['nullable', 'string', 'uuid'],
            'message' => ['required', 'string', 'max:6000'],
            'path' => ['nullable', 'string', 'max:300'],
            // A model from one of the workspace's own keys: "anthropic:claude-opus-5-5".
            'model' => ['nullable', 'string', 'max:100', 'regex:/^(anthropic|openai):[A-Za-z0-9._:\/\[\]-]{1,80}$/'],
        ]);

        $user = $request->user();
        $chat = AssistantAgent::load($data['chat'] ?? null, $workspace, $user);
        // The lock first: a message that can't go ahead uses none of the hour's.
        $lock = Cache::lock("assistant:chat:{$chat['id']}:busy", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return response()->json(['error' => 'Ask AI is still working on your last message.'], 409);
        }
        if (($why = AssistantAllowance::take($workspace)) !== null) {
            $lock->release();

            return response()->json(['error' => $why, 'allowance' => AssistantAllowance::for($workspace)], 429);
        }
        $chat = AssistantAgent::reload($chat);

        return $this->stream($lock, $workspace, fn (callable $emit) => $agent->ask($chat, $workspace, $user, trim($data['message']), $data['path'] ?? null, $emit, $data['model'] ?? null));
    }

    /**
     * What Ask AI can run on here: the models of each of the workspace's
     * own AI keys, for the chat's picker. Empty when it runs on ServerOS's.
     */
    public function models(Workspace $workspace, AiProviders $providers): JsonResponse
    {
        return response()->json([
            'options' => $providers->modelOptions($workspace),
            'settingsUrl' => '/settings/mcp',
        ]);
    }

    #[NotAudited('Approving a change in Ask AI; the tool it runs is audited itself')]
    public function decide(Request $request, Workspace $workspace, AssistantAgent $agent): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'chat' => ['required', 'string', 'uuid'],
            'call' => ['required', 'string', 'max:128'],
            'approve' => ['required', 'boolean'],
            'always' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $chat = AssistantAgent::load($data['chat'], $workspace, $user);
        if ($chat['id'] !== $data['chat']) {
            return response()->json(['error' => 'That chat has expired. Start a new one and ask again.'], 410);
        }
        $lock = Cache::lock("assistant:chat:{$chat['id']}:busy", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return response()->json(['error' => 'Ask AI is still working on your last message.'], 409);
        }
        $chat = AssistantAgent::reload($chat);

        return $this->stream($lock, $workspace, fn (callable $emit) => $agent->decide($chat, $workspace, $user, $data['call'], (bool) $data['approve'], (bool) ($data['always'] ?? false), $emit));
    }

    /**
     * One message's work as newline-delimited JSON, holding the chat's lock.
     *
     * @param  callable(callable(array<string, mixed>): void): void  $work
     */
    private function stream(Lock $lock, Workspace $workspace, callable $work): StreamedResponse
    {
        return response()->stream(function () use ($work, $lock, $workspace) {
            // A deploy takes a few steps and is watched to the end; and a closed tab shouldn't stop a tool halfway.
            set_time_limit(AssistantAgent::BUDGET_SECONDS + 300);
            ignore_user_abort(true);
            $emit = function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $work($emit);
                $emit(['type' => 'done', 'allowance' => AssistantAllowance::for($workspace)]);
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
