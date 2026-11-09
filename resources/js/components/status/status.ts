import { trans } from 'laravel-vue-i18n';
export type ComponentStatus = 'operational' | 'degraded' | 'down' | 'unknown';

export type StatusComponent = {
    key: string;
    label: string;
    status: ComponentStatus;
    group: string | null;
    /** Monitors only: the last response, while it's up. */
    responseMs?: number | null;
    uptime: { percent: number | null; days: (number | null)[] } | null;
};

export type StatusReport = {
    title: string;
    description: string | null;
    notice: {
        text: string;
        kind: 'info' | 'maintenance' | 'incident';
        /** Maintenance under way: why, and when it's expected to end. */
        reason?: string | null;
        until?: string;
    } | null;
    overall: { status: ComponentStatus; label: string };
    server: ComponentStatus;
    sections: { name: string | null; components: StatusComponent[] }[];
    uptimePercent: number | null;
    days: string[];
    updatedAt: string | null;
    checkedAt: string | null;
    /** Monitor downtime over the last two weeks; null with no monitors. */
    incidents: StatusIncident[] | null;
    /** Maintenance planned in the next two weeks, soonest first. */
    upcoming?: StatusMaintenance[];
    /** Incidents the workspace wrote: open ones first, then the last two weeks'. */
    notices?: StatusNotice[];
};

export type StatusNotice = {
    id: number;
    title: string;
    impact: 'degraded' | 'partial' | 'major';
    state: 'investigating' | 'identified' | 'monitoring' | 'resolved';
    /** Which machine, on a page that covers more than one. */
    machine: string | null;
    startedAt: string;
    resolvedAt: string | null;
    /** Newest first. */
    updates: { state: string; message: string; at: string }[];
};

export type StatusMaintenance = {
    startsAt: string;
    endsAt: string;
    reason: string | null;
    /** Which machine, on a page that covers more than one. */
    machine: string | null;
};

export type StatusIncident = {
    label: string;
    startedAt: string;
    resolvedAt: string | null;
    seconds: number;
};

export const statusLabels: Record<ComponentStatus, string> = {
    operational: trans('Operational'),
    degraded: trans('Degraded'),
    down: trans('Down'),
    unknown: trans('Not reporting'),
};

/** Text, dot and soft fill per status, from the dashboard tokens. */
export const statusTone: Record<
    ComponentStatus,
    { text: string; dot: string; soft: string; ring: string }
> = {
    operational: {
        text: 'text-(--ds-green-strong)',
        dot: 'bg-(--ds-green)',
        soft: 'bg-(--ds-green-bg)',
        ring: 'border-(--ds-green-border)',
    },
    degraded: {
        text: 'text-(--ds-amber-strong)',
        dot: 'bg-(--ds-amber)',
        soft: 'bg-(--ds-amber-bg)',
        ring: 'border-(--ds-amber-border)',
    },
    down: {
        text: 'text-(--ds-red)',
        dot: 'bg-(--ds-red)',
        soft: 'bg-(--ds-red-bg)',
        ring: 'border-(--ds-red)/30',
    },
    unknown: {
        text: 'text-(--ds-text-muted)',
        dot: 'bg-(--ds-text-faint)',
        soft: 'bg-(--ds-subtle)',
        ring: 'border-(--ds-border)',
    },
};

export function timeAgo(iso: string | null): string {
    if (!iso) return 'never';

    const seconds = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );

    if (seconds < 60) return trans('just now');
    if (seconds < 3600)
        return trans(':floor min ago', {
            floor: String(Math.floor(seconds / 60)),
        });
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`;

    return `${Math.floor(seconds / 86400)} d ago`;
}

/**
 * A maintenance window in the visitor's own time: "Sat 10 Oct, 05:00 to
 * 05:30", or both dates when it runs past midnight.
 */
export function maintenanceWhen(startsAt: string, endsAt: string): string {
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    const day: Intl.DateTimeFormatOptions = {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    };
    const time: Intl.DateTimeFormatOptions = {
        hour: '2-digit',
        minute: '2-digit',
    };
    const sameDay = start.toDateString() === end.toDateString();

    return trans(':start to :end', {
        start: `${start.toLocaleDateString(undefined, day)}, ${start.toLocaleTimeString(undefined, time)}`,
        end: sameDay
            ? end.toLocaleTimeString(undefined, time)
            : `${end.toLocaleDateString(undefined, day)}, ${end.toLocaleTimeString(undefined, time)}`,
    });
}

/** How soon it starts: "in 3 days", "in 2 hours", "in 20 minutes". */
export function startsIn(iso: string, now: number = Date.now()): string {
    const minutes = Math.round((new Date(iso).getTime() - now) / 60000);

    if (minutes <= 1) return trans('starting now');
    if (minutes < 60)
        return trans('in :count minutes', { count: String(minutes) });
    const hours = Math.round(minutes / 60);
    if (hours < 36)
        return hours === 1
            ? trans('in an hour')
            : trans('in :count hours', { count: String(hours) });

    return trans('in :count days', { count: String(Math.round(hours / 24)) });
}

/** A time in the visitor's own clock, like "05:30". */
export function localTime(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}
