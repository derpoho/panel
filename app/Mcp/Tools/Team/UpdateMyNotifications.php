<?php

namespace App\Mcp\Tools\Team;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use Illuminate\Validation\Rule;

/**
 * Change what the person is emailed from this workspace, as their Profile
 * tab does. Only their own choices; anyone in the workspace may.
 */
final class UpdateMyNotifications extends Tool
{
    public function name(): string
    {
        return 'update_my_notifications';
    }

    public function title(): string
    {
        return 'Change my email preferences';
    }

    public function description(): string
    {
        return 'Changes what you (the person this connection acts as) are emailed from this workspace, as Settings › Profile does. `alert_emails`: all (every alert, and again when it’s fixed), problems (only when something breaks) or none; alerts only go to owners and admins, so for other roles this is kept but has no effect. `weekly_summary`: true or false for the Monday summary email. '
            .'Give one or both; what you leave out stays as it is. Only affects you, never anyone else. Returns your preferences as get_my_notifications does.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'alert_emails' => Schema::string('Which alert emails you get: all, problems or none.', array_keys(Team::ALERT_EMAILS)),
            'weekly_summary' => Schema::boolean('Whether you get the weekly summary email.'),
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    /** Everyone chooses their own emails, whatever their role. */
    public function permission(): ?WorkspacePermission
    {
        return null;
    }

    public function idempotent(): bool
    {
        return true;
    }

    #[NotAudited('Personal: what you’re emailed; changes nothing for the team')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'alert_emails' => ['sometimes', 'required', Rule::in(array_keys(Team::ALERT_EMAILS))],
            'weekly_summary' => ['sometimes', 'required', 'boolean'],
        ], [
            'alert_emails.in' => 'alert_emails must be all, problems or none.',
        ]);
        if ($data === []) {
            throw new ToolError('Say what to change: alert_emails (all, problems or none), weekly_summary (true or false), or both.');
        }

        $changes = [];
        if (array_key_exists('alert_emails', $data)) {
            $changes['alert_emails'] = $data['alert_emails'];
        }
        if (array_key_exists('weekly_summary', $data)) {
            $changes['weekly_summary'] = (bool) $data['weekly_summary'];
        }
        $context->workspace->members()->updateExistingPivot($context->user->id, $changes);

        return [
            ...Team::notifications($context),
            'note' => 'Saved.',
        ];
    }
}
