<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use App\Support\Monitors\MonitorAlerts;

/**
 * Post a test message to a webhook, as the Alerts dialog's Send test
 * does.
 */
final class SendTestAlert extends Tool
{
    public function name(): string
    {
        return 'send_test_alert';
    }

    public function title(): string
    {
        return 'Send a test alert';
    }

    public function description(): string
    {
        return 'Posts a test message (“Test from ServerOS: alerts for … will arrive here.”) to a webhook, to check alerts will arrive: the one given, or the workspace’s saved webhook when left out. It posts to the person’s real channel, so do it when they ask. Returns ok, or what went wrong (not https, can’t be reached from the panel, the status it answered). Nothing is saved: update_alert_settings does that.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'webhook' => Schema::string('The https address to try, like a Slack incoming webhook. Left out: the saved webhook.', maxLength: 2000),
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return false;
    }

    public function openWorld(): bool
    {
        return true;
    }

    #[NotAudited('Sends a test alert; nothing changes')]
    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $saved = is_string($workspace->alert_webhook_url) && $workspace->alert_webhook_url !== '' ? $workspace->alert_webhook_url : null;
        $given = isset($arguments['webhook']) && ! (is_string($arguments['webhook']) && trim($arguments['webhook']) === '');
        if (! $given && $saved === null) {
            throw new ToolError('No webhook is set for this workspace. Pass webhook to try one, or set one with update_alert_settings.');
        }

        $url = $given
            ? $this->validate($arguments, ['webhook' => ['required', 'string', 'max:2000', 'url:https']], ['webhook.url' => 'The webhook needs an https:// address.'])['webhook']
            : $saved;
        Monitors::throttle($context, 'test-alert', 10, 'That’s a lot of test alerts in a minute.');

        $error = app(MonitorAlerts::class)->test($workspace, trim($url));

        return [
            'ok' => $error === null,
            'error' => $error,
            'webhook' => $given ? 'given' : 'saved',
            'host' => parse_url(trim($url), PHP_URL_HOST),
            'note' => $error === null ? 'Sent. Ask the person to check the channel.' : 'It didn’t arrive: '.$error,
        ];
    }
}
