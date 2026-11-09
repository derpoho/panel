import { trans } from 'laravel-vue-i18n';
const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

/** "3 days ago", "in 2 hours", "just now"; "Never" for no date. */
export function timeAgo(iso: string | null | undefined): string {
    if (!iso) return trans('Never');

    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return trans('just now');
}

/** "5 Oct 2026". */
export function shortDate(iso: string | null | undefined): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}
