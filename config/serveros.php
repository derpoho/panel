<?php

return [
    'status_page' => env('SERVEROS_STATUS_PAGE'),

    'egress_ip' => env('SERVEROS_EGRESS_IP'),

    'open_source' => (bool) env('SERVEROS_OPEN_SOURCE', false),

    'edition' => env('SERVEROS_EDITION', 'self-hosted'),

    'sign_ups' => env('SERVEROS_SIGN_UPS', 'closed'),

    // Self-hosted behind a Cloudflare Tunnel (SERVEROS_TUNNEL=cloudflare):
    // cloudflared hands the panel plain HTTP from inside the server, so the
    // visitor's address comes from Cloudflare's headers.
    'tunnel' => env('SERVEROS_TUNNEL'),

    'brand' => [
        'company' => env('SERVEROS_COMPANY'),
        'address' => env('SERVEROS_COMPANY_ADDRESS'),
        'website' => env('SERVEROS_WEBSITE_NAME'),
        'terms_url' => env('SERVEROS_TERMS_URL'),
        'privacy_url' => env('SERVEROS_PRIVACY_URL'),
        'source_url' => env('SERVEROS_SOURCE_URL', 'https://github.com/serveroshq/panel'),
        'agent_email' => env('SERVEROS_AGENT_EMAIL'),
    ],

    'install' => [
        'docker' => (bool) env('SERVEROS_DOCKER', false),
        'path' => env('SERVEROS_INSTALL_PATH'),
    ],

    'updates' => [
        'version_file' => base_path('VERSION'),
        'feed_url' => env('SERVEROS_UPDATE_FEED_URL', 'https://api.github.com/repos/serveroshq/panel/releases/latest'),
    ],

    'telemetry' => [
        'enabled' => (bool) env('SERVEROS_TELEMETRY', true),
        'url' => env('SERVEROS_TELEMETRY_URL', 'https://serveros.com/api/installs/ping'),
    ],

    'self_hosted' => [
        'machines' => env('SERVEROS_SELF_HOSTED_MACHINES'),
        'mcp_calls' => env('SERVEROS_SELF_HOSTED_MCP_CALLS'),
        'log_retention_days' => env('SERVEROS_SELF_HOSTED_LOG_RETENTION_DAYS', 30),
        'log_lines_per_day' => env('SERVEROS_SELF_HOSTED_LOG_LINES_PER_DAY', 5_000_000),
        'activity_retention_days' => env('SERVEROS_SELF_HOSTED_ACTIVITY_RETENTION_DAYS', 365),
        'monitors' => env('SERVEROS_SELF_HOSTED_MONITORS', 1_000),
        'min_monitor_interval' => env('SERVEROS_SELF_HOSTED_MIN_MONITOR_INTERVAL', 30),
        'status_subscribers' => env('SERVEROS_SELF_HOSTED_STATUS_SUBSCRIBERS', 100_000),
        'api_requests_per_minute' => env('SERVEROS_SELF_HOSTED_API_REQUESTS_PER_MINUTE', 1_000),
    ],

    'workflow_outside_emails_per_day' => (int) env('SERVEROS_WORKFLOW_OUTSIDE_EMAILS_PER_DAY', 200),

    'legacy_admin_emails' => (string) env('SERVEROS_ADMIN_EMAILS', ''),
];
