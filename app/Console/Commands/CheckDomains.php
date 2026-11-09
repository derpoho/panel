<?php

namespace App\Console\Commands;

use App\Models\WorkspaceDomain;
use App\Support\Domains\Cloudflare;
use App\Support\Domains\CustomDomains;
use Illuminate\Console\Command;

/**
 * Domains waiting on their owner's DNS: ask Cloudflare how they're doing,
 * often at first and less after, and give up on ones left for a fortnight.
 */
class CheckDomains extends Command
{
    protected $signature = 'domains:check';

    protected $description = 'Check workspace domains waiting on DNS and certificates';

    public function handle(CustomDomains $domains): int
    {
        if (! Cloudflare::enabled()) {
            return self::SUCCESS;
        }

        $pending = WorkspaceDomain::query()->where('state', 'pending')->whereNotNull('cloudflare_id')->get();
        foreach ($pending as $domain) {
            $age = $domain->created_at?->diffInMinutes(now()) ?? 0;
            // Every minute for the first hour, every ten after.
            $every = $age < 60 ? 1 : 10;
            if ($domain->checked_at !== null && $domain->checked_at->gt(now()->subMinutes($every)->addSeconds(5))) {
                continue;
            }
            if ($age > 14 * 24 * 60) {
                $domain->forceFill(['state' => 'failed', 'error' => 'Nothing pointed at ServerOS for two weeks. Check the DNS records and try again.'])->save();
                CustomDomains::forget();

                continue;
            }
            $domains->refresh($domain);
        }

        return self::SUCCESS;
    }
}
