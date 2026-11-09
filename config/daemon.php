<?php

return [
    'install_ready' => (bool) env('DAEMON_INSTALL_READY', false),
    'ca_cert' => env('DAEMON_CA_CERT', storage_path('app/private/daemon-ca.crt')),
    'ca_key' => env('DAEMON_CA_KEY', storage_path('app/private/daemon-ca.key')),
    'gateway_host' => env('DAEMON_GATEWAY_HOST', parse_url((string) env('APP_URL', ''), PHP_URL_HOST)),
    'gateway_secret' => env('DAEMON_GATEWAY_SECRET'),
    'gateway_internal_url' => env('DAEMON_GATEWAY_INTERNAL_URL'),
    'commands_enabled' => (bool) env('DAEMON_COMMANDS_ENABLED', false),
    // The first daemon release that can install chosen packages only
    // (the package_install job). Older daemons drop that job unread.
    'package_install_since' => env('DAEMON_PACKAGE_INSTALL_SINCE', '0.1.1-pre.1'),
    // The first daemon release that can fetch a private repository with a
    // GitHub App token (repo_token on a deploy).
    'repo_token_since' => env('DAEMON_REPO_TOKEN_SINCE', '0.1.1-pre.10'),
    // The first daemon release that can show a container's docker inspect
    // (the service_inspect job). Older daemons drop that job unread.
    'inspect_since' => env('DAEMON_INSPECT_SINCE', '0.1.1-pre.16'),
    // The first daemon release that can remove a systemd service (older
    // ones refuse anything that isn't a container).
    'systemd_remove_since' => env('DAEMON_SYSTEMD_REMOVE_SINCE', '0.1.1-pre.17'),
    // The first daemon release that can mask IP addresses in log lines.
    'log_ip_masking_since' => env('DAEMON_LOG_IP_MASKING_SINCE', '0.1.1-pre.21'),

    // Projects copying a database's data between environments: a command
    // given its settings by name, so passwords stay out of its log.
    'project_data_since' => env('DAEMON_PROJECT_DATA_SINCE', '0.1.1-pre.35'),

    // The first daemon release that can skip services and lines in its logs.
    'log_skip_since' => env('DAEMON_LOG_SKIP_SINCE', '0.1.1-pre.22'),
    // The first daemon release that can say what's using a machine's disk
    // (the disk_usage job). Older daemons drop that job unread.
    'disk_usage_since' => env('DAEMON_DISK_USAGE_SINCE', '0.1.1-pre.19'),
    // The first daemon release that can clone a container (the
    // service_clone job). Older daemons drop that job unread.
    'clone_since' => env('DAEMON_CLONE_SINCE', '0.1.1-pre.23'),
    // The first daemon release that serves *.domain with a certificate per
    // subdomain (on-demand TLS); older ones would ask for a wildcard one.
    'wildcard_domains_since' => env('DAEMON_WILDCARD_DOMAINS_SINCE', '0.1.1-pre.34'),
    // The first daemon release that follows every part of a machine
    // policy: discovery and logs off, and updates that wait for approval.
    // Older ones still mask IPs, and the panel drops logs a policy says
    // no to whatever the daemon sends.
    'policy_since' => env('DAEMON_POLICY_SINCE', '0.1.1-pre.24'),
    // The first daemon release that can install exact package versions
    // (package_install_versions) and hold packages (package_hold), and
    // reports every waiting update in full. Older daemons drop those jobs
    // unread.
    'package_versions_since' => env('DAEMON_PACKAGE_VERSIONS_SINCE', '0.1.1-pre.26'),

    // The first daemon release that can install packages a machine doesn't
    // have yet (package_add), like git for deploys from a repository.
    'package_add_since' => env('DAEMON_PACKAGE_ADD_SINCE', '0.1.1-pre.35'),
    'install_url' => env('DAEMON_INSTALL_URL', rtrim((string) env('APP_URL', ''), '/').'/install.sh'),
    // The script /install.sh serves when the web server has no
    // public/install.sh (DaemonInstallScriptController): the panel's own,
    // resources/daemon/install.sh, unless a package gives its own.
    'install_script' => null,
    'releases_url' => env('DAEMON_RELEASES_URL', 'https://releases.serveros.com'),
    // Where a self-hosted panel copies daemon releases from (the
    // daemon:sync-releases command, hourly on self-hosted installs only):
    // serveros.com's own feed, since CI registers releases there.
    'release_feed_url' => env('DAEMON_RELEASE_FEED_URL', 'https://serveros.com/api/daemon/releases/latest'),
    // Where releases_url is served from: uploaded binaries, and
    // stable/VERSION (what install.sh reads).
    'releases_path' => env('DAEMON_RELEASES_PATH', public_path('releases/daemon')),
    // CI's token for registering releases (POST /api/daemon/releases).
    'deploy_token' => env('SERVEROS_DEPLOY_TOKEN'),
    // Mac port the local test node's port 80 is published on (local-proxy.sh).
    'local_node_port' => env('DAEMON_LOCAL_NODE_PORT'),
    // More than one local test node: each one's own proxy port, by hostname
    // (serveros-node-2=8089,other=8090). Ones not listed use local_node_port.
    'local_node_ports' => env('DAEMON_LOCAL_NODE_PORTS', ''),
    'panel_url' => env('DAEMON_PANEL_URL', rtrim((string) env('APP_URL', ''), '/')),
];
