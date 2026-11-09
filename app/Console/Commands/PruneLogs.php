<?php

namespace App\Console\Commands;

use App\Models\AccountEvent;
use App\Models\Activity;
use App\Models\AdminAction;
use App\Models\ImpersonationSession;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthCode;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\ServiceSample;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Logs\LogCounts;
use App\Support\Logs\LogStore;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

class PruneLogs extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Delete service log lines older than each workspace\'s plan keeps them, activity past the plan\'s 90 to 365 days, account events past a year (failed sign-ins past 90 days), staff actions and View as history past two years, machine usage history older than 30 days, service usage readings past a day (thinned to one a minute after an hour), and spent MCP access';

    public function handle(): int
    {
        $deleted = 0;
        // Workspaces keeping lines in their own database, and whether they
        // want us to prune it. Some keep every line, on purpose.
        $own = WorkspaceLogStore::query()->where('enabled', true)->pluck('prune', 'workspace_id');

        Workspace::query()->with('machines:id,workspace_id')->chunkById(100, function ($workspaces) use (&$deleted, $own): void {
            foreach ($workspaces as $workspace) {
                $machines = $workspace->machines->modelKeys();
                if ($machines === [] || ($own->has($workspace->id) && ! $own->get($workspace->id))) {
                    continue;
                }

                $cutoff = now()->subDays($workspace->logRetentionDays())->getTimestampMs();
                $connection = LogStore::connection($workspace);
                $external = LogStore::isExternal($workspace);

                try {
                    $deleted += LogEntry::on($connection)
                        ->whereIn('machine_id', $machines)
                        ->where('logged_at', '<', $cutoff)
                        ->delete();
                    LogCounts::prune(array_values($workspace->machines->map(fn (Machine $machine) => $machine->id)->all()), $cutoff, $connection);
                } catch (QueryException|PDOException $e) {
                    // One customer's database down mustn't stop the run.
                    if (! $external) {
                        throw $e;
                    }
                    LogStore::markUnreachable($workspace, $e);
                    $this->warn("Couldn't reach workspace {$workspace->id}'s log database: ".LogStore::reason($e));
                } finally {
                    if ($external) {
                        DB::purge($connection);
                    }
                }
            }
        });

        $this->info("Deleted {$deleted} log lines.");

        // Activity: as long as each workspace's plan keeps it.
        $activity = 0;
        Workspace::query()->select(['id', 'plan'])->chunkById(200, function ($workspaces) use (&$activity): void {
            foreach ($workspaces->groupBy(fn (Workspace $w) => $w->activityRetentionDays()) as $days => $group) {
                $activity += Activity::query()
                    ->whereIn('workspace_id', $group->modelKeys())
                    ->where('created_at', '<', now()->subDays((int) $days))
                    ->delete();
            }
        });
        $this->info("Deleted {$activity} activity entries.");

        $account = AccountEvent::prune();
        $this->info("Deleted {$account} account events.");

        // Staff history: two years. Admin actions first, so the sessions
        // they pointed at can go too.
        $staff = AdminAction::query()->where('created_at', '<', now()->subDays(AdminAction::KEEP_DAYS))->delete();
        $viewAs = ImpersonationSession::prune();
        $this->info("Deleted {$staff} staff actions and {$viewAs} View as sessions and pages.");

        $samples = MachineSample::query()->where('ts', '<', now()->subDays(MachineSample::KEEP_DAYS)->getTimestamp())->delete();
        $this->info("Deleted {$samples} machine usage minutes.");

        $serviceSamples = ServiceSample::prune();
        $this->info("Deleted {$serviceSamples} service usage readings.");

        // MCP: the call log, used-up sign-in codes, access that ended a
        // month ago, and apps that registered but never connected.
        $calls = McpToolCall::query()->where('created_at', '<', now()->subDays(McpToolCall::KEEP_DAYS))->delete();
        McpOAuthCode::query()->where('expires_at', '<', now()->subDay())->delete();
        $ended = now()->subDays(30);
        $tokens = McpToken::query()
            ->where(fn ($q) => $q->where('revoked_at', '<', $ended)
                ->orWhere(fn ($q) => $q->where('kind', 'oauth')->where('refresh_expires_at', '<', $ended)))
            ->delete();
        McpOAuthClient::query()->where('created_at', '<', $ended)->doesntHave('tokens')->doesntHave('codes')->delete();
        $this->info("Deleted {$calls} MCP calls and {$tokens} ended MCP connections.");

        return self::SUCCESS;
    }
}
