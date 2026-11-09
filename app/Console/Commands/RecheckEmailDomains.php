<?php

namespace App\Console\Commands;

use App\Models\WorkspaceEmailDomain;
use App\Support\Auth\EmailDomainVerifier;
use Illuminate\Console\Command;

class RecheckEmailDomains extends Command
{
    protected $signature = 'email-domains:recheck';

    protected $description = 'Check each verified domain still has its TXT record; unverify those that lost it';

    public function handle(EmailDomainVerifier $verifier): int
    {
        $lost = 0;

        WorkspaceEmailDomain::query()
            ->whereNotNull('verified_at')
            ->with('workspace')
            ->each(function (WorkspaceEmailDomain $domain) use ($verifier, &$lost): void {
                // DNS not answering changes nothing; only a missing record does.
                if ($verifier->check($domain, bySystem: true) === 'missing') {
                    $this->line("{$domain->domain}: TXT record gone, no longer verified");
                    $lost++;
                }
            });

        $this->info("Unverified {$lost} domain(s).");

        return self::SUCCESS;
    }
}
