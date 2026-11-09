<?php

namespace App\Models\Concerns;

use App\Support\Audit\AuditContext;

/**
 * For records a person starts (deploys, jobs, workflow runs): the name to
 * show for who did it. When staff started it while viewing as someone
 * it reads "ServerOS Support", never the customer they were viewing as.
 */
trait CreditsWhoStartedIt
{
    public function startedByName(): ?string
    {
        if ($this->impersonation_session_id !== null) {
            $word = __(AuditContext::SUPPORT);

            return is_string($word) ? $word : AuditContext::SUPPORT;
        }

        return $this->user?->name;
    }
}
