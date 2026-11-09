<?php

namespace App\Api;

/**
 * The REST API's routes, each one a tool the MCP server has: the same
 * checks, the same answers, so the API, MCP and the dashboard can't drift
 * apart. Path parameters become the tool's arguments of the same name; a
 * GET's query string or any other method's JSON body supplies the rest.
 *
 * Order matters where a fixed segment and a parameter share a place
 * (workflows/reference before workflows/{workflow}).
 */
final class ApiRoutes
{
    /** What each group of routes is about, for the OpenAPI tags. */
    public const TAGS = [
        'Workspace' => 'The workspace itself, search, issues, activity and your own notification settings.',
        'Machines' => 'Servers running the ServerOS daemon: details, maintenance, updates, reboots and the firewall.',
        'Machine groups' => 'Groups of machines, for filtering and status pages.',
        'Services' => 'What runs on a machine: containers and systemd units, their lifecycle, logs and commands.',
        'Service groups' => 'Groups of services on one machine.',
        'Jobs' => 'Work on a machine runs as a job. Follow it until it’s finished.',
        'Apps and deploys' => 'Deploy one-click templates or your own code, ship new versions, roll back and set domains.',
        'Monitors' => 'Uptime checks for sites, ports and machines.',
        'Alerts' => 'Where alerts go.',
        'Status pages' => 'Each machine’s public status page, and the workspace’s fleet page.',
        'Workflows' => 'Automations: a trigger and the steps that follow it, and their runs.',
        'Backups' => 'Backups of the services ServerOS manages, and restoring them.',
        'Logs' => 'Search the logs your machines ship.',
        'Team' => 'Members, roles and invitations.',
    ];

    /**
     * @return list<array{method: string, path: string, tool: string, tag: string}>
     */
    public static function all(): array
    {
        $routes = [];
        foreach (self::table() as $tag => $rows) {
            foreach ($rows as [$method, $path, $tool]) {
                $routes[] = ['method' => $method, 'path' => $path, 'tool' => $tool, 'tag' => $tag];
            }
        }

        return $routes;
    }

    /**
     * Tag => [method, path, tool].
     *
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private static function table(): array
    {
        return [
            'Workspace' => [
                ['GET', 'workspace', 'get_workspace'],
                ['PATCH', 'workspace', 'update_workspace'],
                ['GET', 'search', 'search'],
                ['GET', 'issues', 'list_issues'],
                ['POST', 'issues/dismiss', 'dismiss_issues'],
                ['GET', 'activity', 'get_activity'],
                ['GET', 'me/notifications', 'get_my_notifications'],
                ['PATCH', 'me/notifications', 'update_my_notifications'],
            ],
            'Machines' => [
                ['GET', 'machines', 'list_machines'],
                ['POST', 'machines', 'add_machine'],
                ['GET', 'machines/connections/{enrolment}', 'get_machine_connection_status'],
                ['GET', 'machines/{machine}', 'get_machine'],
                ['PATCH', 'machines/{machine}', 'update_machine'],
                ['DELETE', 'machines/{machine}', 'remove_machine'],
                ['PUT', 'machines/{machine}/icon', 'set_machine_icon'],
                ['DELETE', 'machines/{machine}/icon', 'remove_machine_icon'],
                ['POST', 'machines/{machine}/maintenance', 'start_maintenance'],
                ['DELETE', 'machines/{machine}/maintenance', 'end_maintenance'],
                ['POST', 'machines/{machine}/rescan', 'rescan_machine'],
                ['POST', 'machines/{machine}/clear-data', 'clear_machine_data'],
                ['POST', 'machines/{machine}/updates/check', 'check_updates'],
                ['GET', 'machines/{machine}/updates', 'get_updates_result'],
                ['POST', 'machines/{machine}/updates/install', 'install_updates'],
                ['POST', 'machines/{machine}/packages', 'install_packages'],
                ['POST', 'machines/{machine}/reboot', 'reboot_machine'],
                ['GET', 'package-updates', 'list_package_updates'],
                ['POST', 'package-rollouts', 'create_package_rollout'],
                ['GET', 'package-rollouts', 'get_package_rollout'],
                ['GET', 'package-rollouts/{rollout}', 'get_package_rollout'],
                ['POST', 'machines/{machine}/disk-usage', 'get_disk_usage'],
                ['GET', 'machines/{machine}/firewall', 'get_firewall'],
                ['POST', 'machines/{machine}/firewall/ports', 'open_port'],
                ['DELETE', 'machines/{machine}/firewall/ports/{port}', 'close_port'],
            ],
            'Machine groups' => [
                ['GET', 'machine-groups', 'list_machine_groups'],
                ['POST', 'machine-groups', 'create_machine_group'],
                ['PATCH', 'machine-groups/{group}', 'update_machine_group'],
                ['DELETE', 'machine-groups/{group}', 'delete_machine_group'],
            ],
            'Machine policies' => [
                ['GET', 'machine-policies', 'list_machine_policies'],
                ['POST', 'machine-policies', 'create_machine_policy'],
                ['PATCH', 'machine-policies/{policy}', 'update_machine_policy'],
            ],
            'Services' => [
                ['GET', 'services', 'list_services'],
                ['GET', 'machines/{machine}/services', 'list_services'],
                ['POST', 'machines/{machine}/services/hide', 'hide_service'],
                ['POST', 'machines/{machine}/services/unhide', 'unhide_service'],
                ['GET', 'machines/{machine}/services/{service}', 'get_service'],
                ['PATCH', 'machines/{machine}/services/{service}', 'rename_service'],
                ['DELETE', 'machines/{machine}/services/{service}', 'remove_service'],
                ['POST', 'machines/{machine}/services/{service}/start', 'start_service'],
                ['POST', 'machines/{machine}/services/{service}/stop', 'stop_service'],
                ['POST', 'machines/{machine}/services/{service}/restart', 'restart_service'],
                ['POST', 'machines/{machine}/services/{service}/reload', 'reload_service'],
                ['POST', 'machines/{machine}/services/{service}/adopt', 'adopt_service'],
                ['POST', 'machines/{machine}/services/{service}/repair', 'repair_service'],
                ['POST', 'machines/{machine}/services/{service}/clone', 'clone_service'],
                ['GET', 'machines/{machine}/services/{service}/logs', 'get_service_logs'],
                ['POST', 'machines/{machine}/services/{service}/commands', 'run_command'],
                ['PUT', 'machines/{machine}/services/{service}/icon', 'set_service_icon'],
                ['DELETE', 'machines/{machine}/services/{service}/icon', 'remove_service_icon'],
            ],
            'Service groups' => [
                ['GET', 'machines/{machine}/service-groups', 'list_service_groups'],
                ['POST', 'machines/{machine}/service-groups', 'create_service_group'],
                ['PATCH', 'machines/{machine}/service-groups/{group}', 'update_service_group'],
                ['DELETE', 'machines/{machine}/service-groups/{group}', 'delete_service_group'],
                ['PUT', 'machines/{machine}/service-groups/{group}/icon', 'set_service_group_icon'],
                ['DELETE', 'machines/{machine}/service-groups/{group}/icon', 'remove_service_group_icon'],
            ],
            'Jobs' => [
                ['GET', 'jobs', 'list_jobs'],
                ['GET', 'jobs/{job}', 'get_job'],
                ['POST', 'jobs/{job}/retry', 'retry_job'],
                ['POST', 'jobs/{job}/cancel', 'cancel_job'],
            ],
            'Apps and deploys' => [
                ['GET', 'templates', 'list_templates'],
                ['POST', 'repositories/detect', 'detect_repository'],
                ['POST', 'repositories/services', 'detect_services'],
                ['GET', 'deploys', 'list_deploys'],
                ['POST', 'machines/{machine}/deploys/template', 'deploy_template'],
                ['POST', 'machines/{machine}/deploys/git', 'deploy_from_git'],
                ['GET', 'machines/{machine}/domains/check', 'check_domain'],
                ['GET', 'machines/{machine}/apps/{app}', 'get_app'],
                ['GET', 'machines/{machine}/connections/{service}', 'get_connection'],
                ['POST', 'machines/{machine}/apps/{app}/deploy', 'deploy_new_version'],
                ['POST', 'machines/{machine}/apps/{app}/redeploy', 'redeploy_app'],
                ['POST', 'machines/{machine}/apps/{app}/rollback', 'rollback_app'],
                ['PUT', 'machines/{machine}/apps/{app}/domain', 'set_app_domain'],
                ['PATCH', 'machines/{machine}/apps/{app}/settings', 'update_app_settings'],
            ],
            'Projects' => [
                ['GET', 'projects', 'list_projects'],
                ['POST', 'projects', 'create_project'],
                ['GET', 'projects/{project}', 'get_project'],
                ['POST', 'projects/{project}/parts', 'add_to_project'],
                ['POST', 'projects/{project}/resources', 'add_project_resource'],
                ['POST', 'projects/{project}/connections', 'connect_project_app'],
            ],
            'Monitors' => [
                ['GET', 'monitors', 'list_monitors'],
                ['POST', 'monitors', 'create_monitor'],
                ['GET', 'monitors/{monitor}', 'get_monitor'],
                ['PATCH', 'monitors/{monitor}', 'update_monitor'],
                ['DELETE', 'monitors/{monitor}', 'delete_monitor'],
                ['POST', 'monitors/{monitor}/check', 'check_monitor_now'],
                ['POST', 'monitors/{monitor}/pause', 'pause_monitor'],
                ['POST', 'monitors/{monitor}/resume', 'resume_monitor'],
                ['POST', 'monitors/{monitor}/mute', 'mute_monitor'],
                ['DELETE', 'monitors/{monitor}/mute', 'unmute_monitor'],
            ],
            'Alerts' => [
                ['GET', 'alerts/settings', 'get_alert_settings'],
                ['PATCH', 'alerts/settings', 'update_alert_settings'],
                ['POST', 'alerts/test', 'send_test_alert'],
            ],
            'Status pages' => [
                ['GET', 'status-pages', 'list_status_pages'],
                ['GET', 'status-pages/preview', 'preview_status_page'],
                ['GET', 'status-pages/fleet', 'get_fleet_status_page'],
                ['PATCH', 'status-pages/fleet', 'update_fleet_status_page'],
                ['GET', 'status-pages/incidents', 'list_status_incidents'],
                ['POST', 'status-pages/incidents', 'post_status_incident'],
                ['POST', 'status-pages/incidents/{incident}/updates', 'update_status_incident'],
                ['GET', 'machines/{machine}/status-page', 'get_status_page'],
                ['PATCH', 'machines/{machine}/status-page', 'update_status_page'],
                ['PUT', 'machines/{machine}/status-page/layout', 'arrange_status_page'],
            ],
            'Workflows' => [
                ['GET', 'workflows', 'list_workflows'],
                ['POST', 'workflows', 'create_workflow'],
                ['GET', 'workflows/reference', 'get_workflow_reference'],
                ['GET', 'workflows/templates', 'list_workflow_templates'],
                ['GET', 'workflows/{workflow}', 'get_workflow'],
                ['PATCH', 'workflows/{workflow}', 'update_workflow'],
                ['DELETE', 'workflows/{workflow}', 'delete_workflow'],
                ['POST', 'workflows/{workflow}/runs', 'run_workflow'],
                ['GET', 'workflow-runs', 'list_workflow_runs'],
                ['GET', 'workflow-runs/{run}', 'get_workflow_run'],
                ['POST', 'workflow-runs/{run}/cancel', 'cancel_workflow_run'],
            ],
            'Backups' => [
                ['GET', 'backups', 'list_backups'],
                ['GET', 'backups/destinations', 'list_backup_destinations'],
                ['POST', 'backups/{backup}/restore', 'restore_backup'],
                ['POST', 'machines/{machine}/backups', 'run_backup'],
                ['PATCH', 'machines/{machine}/backup-settings', 'update_backup_settings'],
            ],
            'Logs' => [
                ['GET', 'logs', 'search_logs'],
                ['GET', 'logs/stats', 'get_log_stats'],
            ],
            'Team' => [
                ['GET', 'members', 'list_members'],
                ['PATCH', 'members/{member}', 'change_member_role'],
                ['DELETE', 'members/{member}', 'remove_member'],
                ['GET', 'roles', 'list_roles'],
                ['POST', 'invitations', 'invite_member'],
                ['DELETE', 'invitations/{invitation}', 'cancel_invitation'],
            ],
        ];
    }

    /**
     * The path's parameter names, in order.
     *
     * @return list<string>
     */
    public static function parameters(string $path): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $path, $matches);

        return $matches[1];
    }
}
