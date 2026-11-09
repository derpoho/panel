<?php

return [
    /*
     * The status page served at status.{APP_DOMAIN}: ServerOS's own (the
     * fleet page's slug, like serveros-qbml). Unset, that address is just
     * a reserved workspace name.
     */
    'status_page' => env('SERVEROS_STATUS_PAGE'),

    // The address the panel connects out from, for people to allow through
    // their firewall (a workspace's own log database, for one).
    'egress_ip' => env('SERVEROS_EGRESS_IP'),

    // Who personal emails come from (thanks for applying, the onboarding
    // notes), serveros.founder and serveros.cto, come with the serveros.com
    // package.

    // The promo code shown in the site's top banner and the dashboard's trial
    // banner: a discount code checkout takes (LAUNCH: 10% off the first plan
    // payment, made 2026-10-07); set SERVEROS_PROMO_CODE= (empty) to stop
    // showing it.
    // Whether the site talks about the panel as open source and offers
    // self-hosting (the free plan, the repo, the open-source band and FAQs).
    // Off until the panel's repository is public.
    'open_source' => (bool) env('SERVEROS_OPEN_SOURCE', false),

    // Which edition this install is, and so who decides what a workspace may
    // use (App\Support\Entitlements): "self-hosted" (the default) is
    // someone's own install, where every workspace gets the free edition
    // (and what its license adds); "cloud" is serveros.com, where the plan
    // decides. serveros.com sets SERVEROS_EDITION=cloud, and its package
    // makes cloud the default when it's installed.
    'edition' => env('SERVEROS_EDITION', 'self-hosted'),

    // Who runs this install, for the small print on its pages and emails:
    // the company (and its address, under documents staff send), the name
    // its emails link the panel's address by, and where its terms and
    // privacy policy are (a path on the main domain, or a full address).
    // Unset, as on a self-hosted install: pages name no company and link
    // no terms, the dashboard's footer links the panel's source, and
    // emails sign off with APP_NAME and the panel's own host. The
    // serveros.com package fills in serveros.com's.
    'brand' => [
        'company' => env('SERVEROS_COMPANY'),
        'address' => env('SERVEROS_COMPANY_ADDRESS'),
        'website' => env('SERVEROS_WEBSITE_NAME'),
        'terms_url' => env('SERVEROS_TERMS_URL'),
        'privacy_url' => env('SERVEROS_PRIVACY_URL'),
        // The panel's code, for the dashboard's footer when there's no company.
        'source_url' => env('SERVEROS_SOURCE_URL', 'https://github.com/serveroshq/panel'),
        // The git author of commits a workflow's coding agent makes, when
        // it doesn't push as the GitHub App. Unset: agent@ the panel's domain.
        'agent_email' => env('SERVEROS_AGENT_EMAIL'),
    ],

    // A self-hosted install's web installer (App\Support\Install), until
    // it's set up: whether it runs in the Docker image (which sets the
    // database and the panel's address itself), and where it keeps the
    // setup code before there's a database (storage/app/private/install).
    'install' => [
        'docker' => (bool) env('SERVEROS_DOCKER', false),
        'path' => env('SERVEROS_INSTALL_PATH'),
    ],

    // The install ping: once a day a self-hosted install sends serveros.com a
    // random id for the install, its version, whether it's licensed and a few
    // counts (workspaces, people, machines), so we know how many installs
    // there are. Nothing that says who or where it is. Never sent from
    // serveros.com. `php artisan install:ping --show` prints exactly what's
    // sent; SERVEROS_TELEMETRY=false turns it off. App\Support\InstallPing.
    'telemetry' => [
        'enabled' => (bool) env('SERVEROS_TELEMETRY', true),
        'url' => env('SERVEROS_TELEMETRY_URL', 'https://serveros.com/api/installs/ping'),
    ],

    // The numbers a self-hosted workspace gets. The install's owner pays for
    // the hardware, so these are generous and theirs to change; empty means
    // no limit.
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

    'promo' => [
        'code' => env('SERVEROS_PROMO_CODE', 'LAUNCH'),
        'offer' => env('SERVEROS_PROMO_OFFER', '10% off your first plan'),
    ],

    // Retired: admins are a flag on the account now. Only the is_admin
    // migration reads this, to carry over whoever it named.
    'legacy_admin_emails' => (string) env('SERVEROS_ADMIN_EMAILS', ''),
];
