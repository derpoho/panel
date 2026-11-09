<?php

namespace App\Support\Auth;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Proving a workspace owns a mail domain: a TXT record saying
 * serveros-domain-verification=<token>, on _serveros-verify.<domain> or
 * on the domain itself.
 */
class EmailDomainVerifier
{
    public function __construct(private readonly TxtRecords $txt) {}

    /**
     * acme.com from "Acme.com", "@acme.com", "https://acme.com/" or
     * "ada@acme.com"; null when it isn't a domain.
     */
    public static function normalize(string $input): ?string
    {
        $domain = strtolower(trim($input));
        $domain = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = (string) preg_replace('#[/?\#].*$#', '', $domain);
        if (str_contains($domain, '@')) {
            $domain = substr($domain, strrpos($domain, '@') + 1);
        }
        $domain = rtrim($domain, '.');

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        return strlen($domain) <= 253 && preg_match("/^(?:{$label}\.)+[a-z][a-z0-9-]{0,62}$/", $domain) === 1 ? $domain : null;
    }

    /** Why this domain can't be added to the workspace, or null if it can. */
    public static function problemWith(Workspace $workspace, string $domain): ?string
    {
        $ours = strtolower((string) config('app.domain'));

        return match (true) {
            PublicMailDomains::is($domain) => 'Anyone can get an address on '.$domain.', so it can’t be verified. Use your company’s own domain.',
            $ours !== '' && ($domain === $ours || str_ends_with($domain, '.'.$ours)) => 'That domain is ours. Use your company’s own domain.',
            $workspace->emailDomains()->where('domain', $domain)->exists() => 'You’ve already added '.$domain.'.',
            WorkspaceEmailDomain::query()->where('domain', $domain)->whereNotNull('verified_at')->exists() => 'Another workspace has already verified '.$domain.'.',
            default => null,
        };
    }

    /**
     * Look for the record now. Null when DNS didn't answer, so nothing
     * changes on a hiccup; otherwise whether it's there.
     */
    public function hasRecord(WorkspaceEmailDomain $domain): ?bool
    {
        $answered = false;
        foreach ([$domain->recordName(), $domain->domain] as $host) {
            $records = $this->txt->for($host);
            if ($records === null) {
                continue;
            }
            $answered = true;
            foreach ($records as $record) {
                if (trim($record, " \"\t") === $domain->recordValue()) {
                    return true;
                }
            }
        }

        return $answered ? false : null;
    }

    /**
     * Check the domain and record what changed. A domain another workspace
     * verified first stays unverified here.
     *
     * @return 'verified'|'missing'|'taken'|'no-answer'
     */
    public function check(WorkspaceEmailDomain $domain, bool $bySystem = false): string
    {
        $found = $this->hasRecord($domain);
        $domain->forceFill(['checked_at' => now()]);

        if ($found === null) {
            $domain->save();

            return 'no-answer';
        }

        if ($found && ! $domain->isVerified()) {
            if ($domain->verifiedElsewhere()) {
                $domain->save();

                return 'taken';
            }
            try {
                $domain->forceFill(['verified_at' => now()])->save();
            } catch (UniqueConstraintViolationException) {
                return 'taken';
            }
            Activity::record($domain->workspace, 'email_domain.verified', $domain->domain);
        } elseif (! $found && $domain->isVerified()) {
            $domain->forceFill(['verified_at' => null])->save();
            $bySystem
                ? Activity::system($domain->workspace, 'email_domain.unverified', $domain->domain)
                : Activity::record($domain->workspace, 'email_domain.unverified', $domain->domain);
        } else {
            $domain->save();
        }

        return $found ? 'verified' : 'missing';
    }
}
