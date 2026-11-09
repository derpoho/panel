import { trans } from 'laravel-vue-i18n';
import type { Tone } from '@/lib/jobStatus';

/** The Updates page's data, as UpdatesController sends it. */
export type UpdatesSummary = {
    updates: number;
    packages: number;
    security: number;
    machines: number;
    total: number;
    unchecked: number;
    rebootRequired: number;
    checkedAt: string | null;
    newestCheck: string | null;
};

export type UpdatesMachine = {
    id: number;
    name: string;
    provider: string | null;
    ip: string | null;
    os: string | null;
    release: string;
    online: boolean;
    groupId: number | null;
    updates: number;
    security: number;
    rebootRequired: boolean;
    checkedAt: string | null;
    complete: boolean;
    held: string[];
    canVersions: boolean;
    canPick: boolean;
};

export type PackageVersion = {
    version: string;
    release: string;
    os: string | null;
    machineIds: number[];
};

export type UpdatesPackage = {
    name: string;
    security: boolean;
    machineIds: number[];
    versions: PackageVersion[];
    installed: string[];
    heldOn: number[];
};

export type RolloutState =
    | 'pending'
    | 'running'
    | 'paused'
    | 'done'
    | 'failed'
    | 'cancelled';

export type Rollout = {
    id: number;
    name: string;
    state: RolloutState;
    mode: 'latest' | 'exact';
    everything: boolean;
    packages: { name: string; version: string | null }[];
    allowDowngrade: boolean;
    batchSize: number | null;
    stopOnFailure: boolean;
    reboot: 'never' | 'if_required';
    scheduledFor: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    createdAt: string | null;
    by: string | null;
    total: number;
    finished: number;
    done: number;
    failed: number;
    skipped: number;
    running: number;
};

export type RolloutTarget = {
    machineId: number;
    name: string;
    provider: string | null;
    state: 'pending' | 'running' | 'done' | 'failed' | 'skipped';
    outcome: string | null;
    installed: { name: string; version: string | null }[];
    jobId: string | null;
    rebootJobId: string | null;
    startedAt: string | null;
    finishedAt: string | null;
};

export type RolloutDetail = Rollout & { machines: RolloutTarget[] };

export type MachineGroupChoice = {
    id: number;
    name: string;
    machineIds: number[];
};

/** A rollout's state in words, with a tone for its colour. */
export function rolloutStatus(rollout: Rollout): {
    label: string;
    tone: Tone;
    busy: boolean;
} {
    switch (rollout.state) {
        case 'pending':
            return rollout.scheduledFor
                ? { label: trans('Planned'), tone: 'blue', busy: false }
                : { label: trans('Starting'), tone: 'blue', busy: true };
        case 'running':
            return { label: trans('Running'), tone: 'amber', busy: true };
        case 'paused':
            return { label: trans('Paused'), tone: 'neutral', busy: false };
        case 'done':
            return { label: trans('Done'), tone: 'green', busy: false };
        case 'failed':
            return { label: trans('Failed'), tone: 'red', busy: false };
        default:
            return { label: trans('Cancelled'), tone: 'neutral', busy: false };
    }
}

/** One machine's part in a rollout, in words. */
export function targetStatus(target: RolloutTarget): {
    label: string;
    tone: Tone;
    busy: boolean;
} {
    switch (target.state) {
        case 'pending':
            return { label: trans('Waiting'), tone: 'neutral', busy: false };
        case 'running':
            return target.rebootJobId
                ? { label: trans('Restarting'), tone: 'amber', busy: true }
                : { label: trans('Installing'), tone: 'amber', busy: true };
        case 'done':
            return { label: trans('Done'), tone: 'green', busy: false };
        case 'failed':
            return { label: trans('Failed'), tone: 'red', busy: false };
        default:
            return { label: trans('Skipped'), tone: 'neutral', busy: false };
    }
}

export const toneText: Record<Tone, string> = {
    green: 'text-(--ds-green)',
    red: 'text-(--ds-red)',
    amber: 'text-(--ds-amber)',
    blue: 'text-(--ds-blue)',
    neutral: 'text-(--ds-text-faint)',
};

/** Whether a rollout can still change: not finished or called off. */
export function rolloutOpen(rollout: Rollout): boolean {
    return ['pending', 'running', 'paused'].includes(rollout.state);
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

/** A JSON request to the panel; throws with its message when it fails. */
export async function sendJson<T>(
    method: 'GET' | 'POST' | 'DELETE',
    url: string,
    body?: object,
): Promise<T> {
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            'X-CSRF-TOKEN': csrf(),
        },
    };
    if (body && method !== 'GET') {
        init.body = JSON.stringify(body);
    }
    const response = await fetch(url, init);
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = json.errors
            ? (Object.values(json.errors).flat() as string[])
            : [];
        throw new Error(
            errors.join(' ') ||
                json.error ||
                json.message ||
                trans('That didn’t work. Try again.'),
        );
    }

    return json as T;
}
