<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\ToolError;
use App\Models\MachinePolicy;
use App\Support\Machines\MachinePolicies;

/**
 * Machine policies as the tools name them: by id or name inside the
 * workspace, "default" for the workspace's default, and shown the same way
 * in every answer.
 */
final class MachinePolicyRefs
{
    /** A policy in this workspace, by id, name (any case) or "default". */
    public static function find(McpContext $context, mixed $reference): MachinePolicy
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new ToolError('Say which policy: its id or name. list_machine_policies shows them.');
        }
        if (mb_strtolower($reference) === 'default') {
            return MachinePolicies::defaultFor($context->workspace);
        }

        $query = $context->workspace->machinePolicies();
        if (ctype_digit($reference) && ($policy = (clone $query)->find((int) $reference)) !== null) {
            return $policy;
        }

        $matches = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            throw new ToolError("Several policies are called “{$reference}”: ".$matches->map(fn (MachinePolicy $p) => "#{$p->id}")->implode(', ').'. Use the id.');
        }

        throw new ToolError("No machine policy “{$reference}” in {$context->workspace->name}. list_machine_policies shows them.");
    }

    /**
     * A policy for answers: its settings in the API's words.
     *
     * @return array<string, mixed>
     */
    public static function present(MachinePolicy $policy, ?int $machines = null): array
    {
        return array_filter([
            'id' => $policy->id,
            'name' => $policy->name,
            'default' => $policy->is_default,
            'discovery' => $policy->discovery,
            'logs' => $policy->logs,
            'logIpMasking' => $policy->log_ip_masking,
            'securityWatch' => $policy->security_watch,
            'errorReports' => $policy->error_reports,
            'statusPageNewServices' => $policy->status_page_new,
            'updates' => $policy->updates,
            'pinnedVersion' => $policy->updates === 'pinned' ? $policy->pinned_version : null,
            'appliesTo' => $policy->applies_to,
            'machines' => $machines,
        ], fn ($value) => $value !== null);
    }
}
