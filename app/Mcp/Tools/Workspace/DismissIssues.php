<?php

namespace App\Mcp\Tools\Workspace;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\AuditContext;
use App\Support\Health\WorkspaceHealth;

/**
 * "Mark as read" on the Home's Needs attention list: hides items for
 * everyone in the workspace until they happen again. The same check and
 * the same activity line as the dashboard's button.
 */
final class DismissIssues extends Tool
{
    public function __construct(
        private readonly WorkspaceHealth $health,
        private readonly Issues $issues,
    ) {}

    public function name(): string
    {
        return 'dismiss_issues';
    }

    public function title(): string
    {
        return 'Mark Needs attention items as read';
    }

    public function description(): string
    {
        return 'Mark items in the workspace Home’s “Needs attention” list as read, like its “Mark as read” buttons. It hides them for everyone in the workspace, not just this person; it fixes nothing. An item comes back by itself if it happens again (it recovers and then fails again). '
            .'Pass the ids from list_issues, or all: true for everything listed now (ids are then ignored). Ask the person before hiding items they haven’t seen. Returns how many were marked, the ids that weren’t in the list (already marked as read, cleared up, or not an issue id), and what’s left.';
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'ids' => Schema::array(Schema::string('An item’s id, as list_issues returns it.', maxLength: 300), 'The items to mark as read.', 200),
            'all' => Schema::boolean('Mark everything in the list now as read, instead of naming ids.'),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        // As the dashboard checks them, unless it's everything.
        $data = $this->validate(Rules::given($arguments), [
            'all' => ['nullable', 'boolean'],
            'ids' => ['exclude_if:all,true', 'required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string', 'max:300'],
        ], ['ids.required' => 'Say which items: ids from list_issues, or all: true.']);

        $shown = array_column($this->issues->current($context), 'id');
        $asked = (bool) ($data['all'] ?? false) ? $shown : array_values(array_unique($data['ids']));

        // Only what's listed now: marking an item twice changes nothing.
        $marking = array_values(array_intersect($asked, $shown));
        $marked = $marking === [] ? 0 : $this->health->dismiss($context->workspace, $marking);
        if ($marked > 0) {
            $context->record('issues.dismiss', $marked === 1 ? '1 item' : "{$marked} items");
        } else {
            AuditContext::nothingChanged();
        }

        $left = $this->issues->current($context);

        return [
            'marked' => $marked,
            'notListed' => array_values(array_diff($asked, $shown)),
            'left' => count($left),
            'needsAttention' => count(array_filter($left, fn (array $issue) => $issue['severity'] !== WorkspaceHealth::NOTICE)),
            'url' => $context->url('/'),
        ];
    }
}
