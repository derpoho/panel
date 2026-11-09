import { trans } from 'laravel-vue-i18n';

/**
 * Machine policies: what a workspace's machines collect and send.
 * A machine follows its own pick, else its group's, else the default.
 */
export type IpMasking = 'off' | 'partial' | 'hash';
export type UpdateMode = 'automatic' | 'approve' | 'pinned';

export type PolicySettings = {
    discovery: boolean;
    logs: boolean;
    log_ip_masking: IpMasking;
    security_watch: boolean;
    /** The daemon's crash and error reports, scrubbed, to ServerOS. */
    error_reports: boolean;
    status_page_new: boolean;
    updates: UpdateMode;
    /** The daemon release machines stay on, when updates are pinned. */
    pinned_version?: string | null;
};

/** A policy's rule: machines whose field holds this value (any case). */
export type PolicyRule = { field: string; value: string };

export type MachinePolicy = PolicySettings & {
    id: number;
    name: string;
    isDefault: boolean;
    /** Machines it applies to by their details, any of these. */
    appliesTo?: PolicyRule[];
};

export type PolicyChoice = { id: number; name: string; isDefault: boolean };

/** Where a new policy starts: everything on, nothing published by itself. */
export const policyBaseline: PolicySettings = {
    discovery: true,
    logs: true,
    log_ip_masking: 'off',
    security_watch: true,
    error_reports: true,
    status_page_new: false,
    updates: 'automatic',
    pinned_version: null,
};

export function policySettings(policy: PolicySettings): PolicySettings {
    return {
        discovery: policy.discovery,
        logs: policy.logs,
        log_ip_masking: policy.log_ip_masking,
        security_watch: policy.security_watch,
        error_reports: policy.error_reports ?? true,
        status_page_new: policy.status_page_new,
        updates: policy.updates,
        pinned_version: policy.pinned_version ?? null,
    };
}

/** A field's name in words: "Region", or a label's own key. */
export function fieldLabel(field: string): string {
    if (field.startsWith('label:')) return field.slice(6);

    return (
        {
            provider: trans('Provider'),
            region: trans('Region'),
            tier: trans('Tier'),
        }[field] ?? field
    );
}

export type PolicyLine = { key: string; title: string; body: string };

/**
 * What a server following these settings sends to ServerOS, and what
 * stays on it, in words.
 */
export function policySends(settings: PolicySettings): {
    sent: PolicyLine[];
    kept: PolicyLine[];
} {
    const sent: PolicyLine[] = [
        {
            key: 'health',
            title: trans('Health'),
            body: trans('CPU, memory, disk and network, and whether it’s up'),
        },
    ];
    const kept: PolicyLine[] = [];

    (settings.discovery ? sent : kept).push({
        key: 'discovery',
        title: trans('Services and ports'),
        body: settings.discovery
            ? trans('What runs, whether it’s up, and the ports it listens on')
            : trans('Nothing is looked for until you scan it yourself'),
    });
    (settings.logs ? sent : kept).push({
        key: 'logs',
        title: trans('Logs'),
        body: !settings.logs
            ? trans('Only shown live when you open them, never stored')
            : settings.log_ip_masking === 'partial'
              ? trans(
                    'Service logs, with the last part of each IP address removed',
                )
              : settings.log_ip_masking === 'hash'
                ? trans('Service logs, with each IP address swapped for a code')
                : trans('Service logs, as they are'),
    });
    (settings.security_watch ? sent : kept).push({
        key: 'security',
        title: trans('Security checks'),
        body: settings.security_watch
            ? trans(
                  'SSH settings, failed sign-ins and whether security updates are on',
              )
            : trans('SSH settings and sign-ins aren’t read'),
    });
    (settings.error_reports ? sent : kept).push({
        key: 'errors',
        title: trans('Error reports'),
        body: settings.error_reports
            ? trans(
                  'When the daemon crashes or hits an error, with secrets, hostnames and IPs removed',
              )
            : trans('The daemon’s crashes and errors stay on the server'),
    });
    kept.push({
        key: 'files',
        title: trans('Files and database contents'),
        body: trans('Backups go to your own storage, not ours'),
    });

    return { sent, kept };
}

/** A short line for a policy, for lists: "Logs off · Updates wait". */
export function policySummary(settings: PolicySettings): string {
    const parts: string[] = [];
    if (!settings.discovery) parts.push(trans('No discovery'));
    parts.push(
        !settings.logs
            ? trans('Logs off')
            : settings.log_ip_masking === 'off'
              ? trans('Logs on')
              : trans('Logs with IPs masked'),
    );
    if (!settings.security_watch) parts.push(trans('No security checks'));
    if (!settings.error_reports) parts.push(trans('No error reports'));
    if (settings.status_page_new) parts.push(trans('New services go public'));
    parts.push(
        settings.updates === 'pinned'
            ? trans('Stays on :version', {
                  version: settings.pinned_version ?? '',
              })
            : settings.updates === 'approve'
              ? trans('Updates wait for approval')
              : trans('Updates on their own'),
    );

    return parts.join(' · ');
}
