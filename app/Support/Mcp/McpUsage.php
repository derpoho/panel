<?php

namespace App\Support\Mcp;

use App\Enums\Currency;
use App\Models\McpCallPack;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AI tool calls: how many a workspace may make this month and how many
 * it has. One call is one tool an AI app ran through the workspace's MCP
 * server and got an answer from, however much work it did inside; calls
 * that failed or were refused don't count. Allowances reset on the 1st of
 * each month (UTC), and packs bought (a package's billing adds them) add
 * to the month they're bought in.
 */
class McpUsage
{
    /** Calls in one pack. */
    public const int PACK_CALLS = 1000;

    /** What a pack costs, in pence. */
    public const int PACK_PRICE = 500;

    /**
     * What a pack costs in a currency's smallest unit. USD and EUR match
     * what checkout charges in them.
     */
    public static function packPrice(Currency $currency = Currency::GBP): int
    {
        return match ($currency) {
            Currency::GBP => self::PACK_PRICE,
            Currency::USD, Currency::EUR => 600,
        };
    }

    /** Most packs in one checkout. */
    public const int MAX_PACKS = 10;

    /** This month, as the usage table keys it: 2026-10. */
    public static function period(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m');
    }

    /** When this month's allowance starts again. */
    public static function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfMonth()->addMonth();
    }

    public static function used(Workspace $workspace): int
    {
        return (int) DB::table('mcp_usage')
            ->where('workspace_id', $workspace->id)
            ->where('period', self::period())
            ->value('calls');
    }

    /** Extra calls from packs bought this month. */
    public static function extra(Workspace $workspace): int
    {
        return (int) McpCallPack::query()
            ->where('workspace_id', $workspace->id)
            ->where('period', self::period())
            ->sum('calls');
    }

    /** Calls the workspace may make this month in all: null for unlimited. */
    public static function limit(Workspace $workspace): ?int
    {
        $included = $workspace->mcpCallLimit();

        return $included === null ? null : $included + self::extra($workspace);
    }

    /**
     * Take one of this month's calls for a tool about to run, or null when
     * none are left. One UPDATE that only matches while the count is under
     * the limit, so calls running at the same time can't take more than
     * there are. Returns the month it counted against, for release().
     */
    public static function claim(Workspace $workspace): ?string
    {
        $period = self::period();
        $now = now();
        $limit = self::limit($workspace);

        DB::table('mcp_usage')->insertOrIgnore(['workspace_id' => $workspace->id, 'period' => $period, 'calls' => 0, 'created_at' => $now, 'updated_at' => $now]);

        $claimed = DB::table('mcp_usage')
            ->where('workspace_id', $workspace->id)
            ->where('period', $period)
            ->when($limit !== null, fn ($q) => $q->where('calls', '<', $limit))
            ->increment('calls', 1, ['updated_at' => $now]);

        return $claimed === 1 ? $period : null;
    }

    /** Give a claimed call back: the tool failed or was refused, and only calls that work count. */
    public static function release(Workspace $workspace, string $period): void
    {
        DB::table('mcp_usage')
            ->where('workspace_id', $workspace->id)
            ->where('period', $period)
            ->where('calls', '>', 0)
            ->decrement('calls', 1, ['updated_at' => now()]);
    }

    /**
     * Usage as pages and tools show it.
     *
     * @return array{used: int, included: int|null, extra: int, limit: int|null, unlimited: bool, resetsAt: string, packCalls: int, packPrice: int, currency: string, maxPacks: int}
     */
    public static function summary(Workspace $workspace, Currency $currency = Currency::GBP): array
    {
        $included = $workspace->mcpCallLimit();
        $extra = self::extra($workspace);

        return [
            'used' => self::used($workspace),
            'included' => $included,
            'extra' => $extra,
            'limit' => $included === null ? null : $included + $extra,
            'unlimited' => $included === null,
            'resetsAt' => self::resetsAt()->toIso8601String(),
            'packCalls' => self::PACK_CALLS,
            'packPrice' => self::packPrice($currency),
            'currency' => $currency->value,
            'maxPacks' => self::MAX_PACKS,
        ];
    }
}
