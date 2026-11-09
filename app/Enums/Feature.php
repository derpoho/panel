<?php

namespace App\Enums;

/**
 * Something a workspace may or may not be allowed to use. Which ones it has
 * comes from App\Support\Entitlements\Entitlements: the plan on serveros.com,
 * or the edition on a self-hosted install.
 */
enum Feature: string
{
    /** People can be given the Member and Viewer roles, not only Admin. */
    case StaffRoles = 'staff_roles';

    /** The workspace can define its own roles from the permissions. */
    case CustomRoles = 'custom_roles';

    /** Single sign-on through the company's identity provider (SAML or OIDC). */
    case Sso = 'sso';

    /** People added, updated and removed by the identity provider over SCIM. */
    case Scim = 'scim';

    /** The workspace's own domains, for the dashboard and its status page. */
    case CustomDomains = 'custom_domains';

    /** Logs kept in a Postgres the workspace owns, instead of ours. */
    case OwnLogStorage = 'own_log_storage';

    /** One status page for every machine in the workspace. */
    case FleetStatusPage = 'fleet_status_page';

    /** A status page for each machine. */
    case StatusPages = 'status_pages';

    /** Workflows. */
    case Automations = 'automations';
}
