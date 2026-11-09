<?php

namespace App\Support\Assistant;

use App\Models\Workspace;
use App\Support\Ai\AiProviders;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How much a workspace may ask Ask AI: messages an hour, more when it runs
 * on the workspace's own AI key (which it pays for), plus a cap on the
 * steps one message can take so a loop can't run away. A little more than
 * the server agent's investigations, since a chat takes a few messages.
 *
 * Changes Ask AI makes (deploys, workflows, restarts) also count as the
 * workspace's AI tool calls for the month, like any AI app's over MCP;
 * looking things up doesn't.
 */
final class AssistantAllowance
{
    /** Messages an hour on ServerOS's AI. */
    public const PER_HOUR = 30;

    /** On the workspace's own key: still a ceiling, so a script can't run up its bill. */
    public const PER_HOUR_OWN_KEY = 150;

    /** Model turns one message may take (each can call tools). */
    public const MAX_TURNS = 12;

    /** Tools one message may run in all. */
    public const MAX_TOOL_CALLS = 20;

    /** @return array{used: int, limit: int, ownKey: bool, resetsIn: int|null} */
    public static function for(Workspace $workspace): array
    {
        $ownKey = app(AiProviders::class)->forAgent($workspace)['own'];
        $limit = $ownKey ? self::PER_HOUR_OWN_KEY : self::PER_HOUR;
        $key = self::key($workspace);
        $used = min($limit, RateLimiter::attempts($key));

        return ['used' => $used, 'limit' => $limit, 'ownKey' => $ownKey, 'resetsIn' => $used > 0 ? RateLimiter::availableIn($key) : null];
    }

    /**
     * Why the workspace can't ask now, in words, or null when it can (and
     * the message is counted).
     */
    public static function take(Workspace $workspace): ?string
    {
        $allowance = self::for($workspace);
        if ($allowance['used'] >= $allowance['limit']) {
            $minutes = max(1, (int) ceil(($allowance['resetsIn'] ?? 60) / 60));

            return "You’ve used this hour’s {$allowance['limit']} messages to Ask AI. You can ask again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.'
                .($allowance['ownKey'] ? '' : ' Connect your own AI key in Settings › AI and MCP for '.self::PER_HOUR_OWN_KEY.' an hour.');
        }
        RateLimiter::hit(self::key($workspace), 3600);

        return null;
    }

    private static function key(Workspace $workspace): string
    {
        return "assistant:hour:{$workspace->id}";
    }
}
