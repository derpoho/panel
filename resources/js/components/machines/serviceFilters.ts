import { trans } from 'laravel-vue-i18n';
import { passes } from '@/components/machines/serviceFormat';
import type {
    Service,
    ServiceFilter,
    ServiceStat,
} from '@/components/machines/serviceFormat';

export type StatusFilter = ServiceFilter;
export type ServiceSortKey = 'cpu' | 'memory' | 'uptime' | 'name';

/** A service on the workspace list, which says where it runs. */
export type WorkspaceService = Service & {
    machine: { id: number; name: string };
    stat: ServiceStat | null;
};

/** The status pills, with how many services each holds. */
export function statusTabs(
    services: Service[],
): { value: StatusFilter; label: string; count: number }[] {
    return (
        [
            ['all', trans('All')],
            ['managed', trans('Managed')],
            ['running', trans('Running')],
            ['stopped', trans('Stopped')],
            ['problems', trans('Problems')],
        ] as const
    ).map(([value, label]) => ({
        value,
        label,
        count: services.filter((s) => passes(s, value)).length,
    }));
}

export const serviceSortOptions: { value: ServiceSortKey; label: string }[] = [
    { value: 'cpu', label: trans('CPU usage') },
    { value: 'memory', label: trans('Memory') },
    { value: 'uptime', label: trans('Uptime') },
    { value: 'name', label: trans('Name') },
];
