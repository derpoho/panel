import { trans } from 'laravel-vue-i18n';
import type { Machine } from '@/components/machines/machines';

export type Service = NonNullable<Machine['inventory']>['services'][number];

/** Latest usage for one service, from the daemon's per-service telemetry. */
export type ServiceStat = {
    cpu: number | null;
    memUsed: number | null;
    memLimit: number | null;
    pids: number | null;
    netRx: number | null;
    netTx: number | null;
    startedAt: number | null;
    restarts: number | null;
    at: number | null;
};

export type StatusGroup = 'running' | 'stopped' | 'problems';

/** The pills over a list of services: a status, or only ServerOS's own. */
export type ServiceFilter = 'all' | 'managed' | StatusGroup;

/** One ServerOS deployed or adopted, as the daemon's scan marks it. */
export function isManaged(service: Service): boolean {
    return service.details?.managed === 'true';
}

/** Whether a service shows under that pill. */
export function passes(service: Service, filter: ServiceFilter): boolean {
    if (filter === 'all') return true;
    if (filter === 'managed') return isManaged(service);

    return statusGroup(service) === filter;
}

export function statusGroup(service: Service): StatusGroup {
    if (service.status === 'running') return 'running';
    if (['failed', 'restarting'].includes(service.status)) return 'problems';

    return 'stopped';
}

export function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    if (value >= 1024 ** 3) return `${(value / 1024 ** 3).toFixed(1)} GiB`;
    if (value >= 1024 ** 2) return `${(value / 1024 ** 2).toFixed(1)} MiB`;
    if (value >= 1024) return `${Math.round(value / 1024)} KiB`;

    return `${value} B`;
}

export function uptime(startedAt: number | null | undefined): string {
    if (!startedAt) return '—';

    const seconds = Math.max(0, Math.floor(Date.now() / 1000 - startedAt));
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);

    if (d) return `${d}d ${h}h`;
    if (h) return `${h}h ${m}m`;
    if (m) return `${m}m`;

    return `${seconds}s`;
}

/** Cgroups report "no limit" as a huge number; treat that as none. */
export function memLimit(stat: ServiceStat | null): number | null {
    return stat?.memLimit && stat.memLimit < 2 ** 50 ? stat.memLimit : null;
}

const statusTone: Record<string, { dot: string; label: string }> = {
    running: { dot: 'bg-(--ds-green)', label: trans('Running') },
    stopped: { dot: 'bg-(--ds-text-faint)', label: trans('Stopped') },
    restarting: { dot: 'bg-(--ds-amber)', label: trans('Restarting') },
    failed: { dot: 'bg-(--ds-red)', label: trans('Failed') },
};

export function status(service: Service): { dot: string; label: string } {
    return (
        statusTone[service.status] ?? {
            dot: 'bg-(--ds-text-faint)',
            label: service.status,
        }
    );
}

export const serviceColumns =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,2.2fr)_6.5rem_minmax(0,1.4fr)_minmax(0,1.2fr)_5.5rem_minmax(0,1fr)_1.75rem]';

/** The same, with a Machine column after Service, for the workspace list. */
export const serviceColumnsWithMachine =
    'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_6.5rem_minmax(0,1.3fr)_minmax(0,1.1fr)_5.5rem_minmax(0,1fr)_1.75rem]';
