import { trans } from 'laravel-vue-i18n';

/**
 * A daemon job's state in words, with a tone for its colour: shared by the
 * status pill (JobStatusBadge) and the deployments list.
 */
export type Tone = 'blue' | 'amber' | 'green' | 'red' | 'neutral';

export type JobStatus = { label: string; tone: Tone; busy: boolean };

/** The daemon's phase names, as people would say them. */
const phases: Record<string, string> = {
    prepare: trans('Preparing'),
    fetch: trans('Fetching'),
    files: trans('Writing files'),
    env: trans('Configuring'),
    build: trans('Building'),
    start: trans('Starting'),
    health: trans('Checking health'),
    proxy: trans('Routing'),
    verify: trans('Verifying'),
    record: trans('Recording'),
    drain: trans('Finishing up'),
};

export function jobStatus(
    state: string,
    phase?: string | null,
    progress?: number | null,
): JobStatus {
    switch (state) {
        case 'queued':
            return { label: trans('Queued'), tone: 'blue', busy: true };
        case 'running': {
            const step = phase ? phases[phase] : null;
            const percent =
                progress !== null && progress !== undefined
                    ? ` · ${progress}%`
                    : '';

            return {
                label: `${step ?? 'Running'}${percent}`,
                tone: 'amber',
                busy: true,
            };
        }
        case 'succeeded':
            return { label: trans('Done'), tone: 'green', busy: false };
        case 'failed':
            return { label: trans('Failed'), tone: 'red', busy: false };
        case 'refused':
            return { label: trans('Refused'), tone: 'red', busy: false };
        case 'timed_out':
            return { label: trans('Timed out'), tone: 'red', busy: false };
        case 'cancelled':
            return { label: trans('Cancelled'), tone: 'neutral', busy: false };
        default:
            return {
                label: state.replaceAll('_', ' '),
                tone: 'neutral',
                busy: false,
            };
    }
}
