import { trans } from 'laravel-vue-i18n';
export type MonitorStatus = 'pending' | 'up' | 'down' | 'paused';

export type Monitor = {
    id: number;
    name: string;
    type: 'http' | 'tcp';
    url: string | null;
    host: string | null;
    port: number | null;
    target: string;
    machineId: number | null;
    interval: number;
    timeout: number;
    expectedStatus: number | null;
    keyword: string | null;
    paused: boolean;
    alerts: boolean;
    status: MonitorStatus;
    since: string | null;
    lastCheckedAt: string | null;
    responseMs: number | null;
    error: string | null;
    sslExpiresAt: string | null;
    /** Alerts held until then. */
    mutedUntil: string | null;
    uptime30: number | null;
    /** The last 30 days, oldest first: uptime % or null. */
    days: (number | null)[];
};

export type MachineOption = { id: number; name: string; ip: string | null };

export const statusLook: Record<
    MonitorStatus,
    { dot: string; label: string; text: string }
> = {
    up: {
        dot: 'bg-(--ds-green)',
        label: trans('Up'),
        text: 'text-(--ds-green)',
    },
    down: {
        dot: 'bg-(--ds-red)',
        label: trans('Down'),
        text: 'text-(--ds-red)',
    },
    pending: {
        dot: 'bg-(--ds-text-faint)',
        label: trans('Checking'),
        text: 'text-(--ds-text-muted)',
    },
    paused: {
        dot: 'bg-(--ds-text-faint)',
        label: trans('Paused'),
        text: 'text-(--ds-text-muted)',
    },
};

export const intervals = [30, 60, 180, 300, 600, 1800] as const;

export function every(seconds: number): string {
    return seconds < 60 ? `${seconds} s` : `${seconds / 60} min`;
}

export function percent(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    if (value >= 99.995) return '100%';

    return `${value.toFixed(value >= 99 ? 2 : 1).replace(/\.?0+$/, '')}%`;
}

export function ms(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return value >= 1000 ? `${(value / 1000).toFixed(1)} s` : `${value} ms`;
}

export function dayTone(value: number | null): string {
    if (value === null) return 'bg-(--ds-active)';
    if (value >= 99.9) return 'bg-(--ds-green)';
    if (value >= 95) return 'bg-(--ds-amber)';

    return 'bg-(--ds-red)';
}

/** "4 min", "3 h", "2 d": how long, for "down for …". */
export function span(fromIso: string | null, now = Date.now()): string {
    if (!fromIso) return '';
    const seconds = Math.max(
        0,
        Math.round((now - new Date(fromIso).getTime()) / 1000),
    );

    return duration(seconds);
}

export function duration(seconds: number): string {
    if (seconds < 60) return `${seconds} s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) {
        const m = Math.floor((seconds % 3600) / 60);

        return `${Math.floor(seconds / 3600)} h${m ? ` ${m} min` : ''}`;
    }
    const h = Math.floor((seconds % 86400) / 3600);

    return `${Math.floor(seconds / 86400)} d${h ? ` ${h} h` : ''}`;
}

export function ago(iso: string | null, now = Date.now()): string {
    if (!iso) return trans('Not yet');

    return `${span(iso, now)} ago`;
}

/** Days until a certificate expires, rounded down. */
export function daysUntil(iso: string | null, now = Date.now()): number | null {
    if (!iso) return null;

    return Math.floor((new Date(iso).getTime() - now) / 86_400_000);
}
