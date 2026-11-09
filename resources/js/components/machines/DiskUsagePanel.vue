<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check, LoaderCircle } from '@lucide/vue';
import {
    SolarCopy as Copy,
    SolarHardDrive as HardDrive,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';

/**
 * "See what's using it": the daemon scans the machine's disk, read-only,
 * and this lists the biggest folders and what's safe to clear, with the
 * command for each. Nothing is deleted from here. MachineDiskUsageController
 * does the work.
 */
const props = defineProps<{
    machineId: number;
    canManage: boolean;
    online: boolean;
}>();

type Scan = {
    jobId: string;
    state: string;
    finished: boolean;
    error: { message?: string } | null;
    used: number | null;
    available: number | null;
    partial: boolean;
    dirs: { path: string; bytes: number }[];
    suggestions: { title: string; detail: string; command: string | null }[];
};

const scan = ref<Scan | null>(null);
const scanning = ref(false);
const problem = ref<string | null>(null);
const copied = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let active = true;

onBeforeUnmount(() => {
    active = false;
    clearTimeout(timer);
});

const biggest = computed(() => scan.value?.dirs[0]?.bytes ?? 0);

function bytes(value: number | null): string {
    if (value === null) return trans('Unknown');
    // Binary units, like the rest of the machine page.
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let n = value;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(i >= 3 ? 1 : 0)} ${units[i]}`;
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function start(): Promise<void> {
    scanning.value = true;
    problem.value = null;
    try {
        const response = await fetch(
            `/machines/${props.machineId}/disk-usage`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            },
        );
        const json = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(
                json.error ??
                    json.message ??
                    trans('That didn’t work. Try again.'),
            );
        }
        follow((json as Scan).jobId);
    } catch (e) {
        problem.value = (e as Error).message;
        scanning.value = false;
    }
}

function follow(jobId: string): void {
    const tick = async () => {
        if (!active) return;
        try {
            const response = await fetch(
                `/machines/${props.machineId}/disk-usage/${jobId}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                },
            );
            const status = (await response.json()) as Scan;
            if (status.finished || status.state === 'stale') {
                scanning.value = false;
                if (status.state === 'succeeded') {
                    scan.value = status;
                } else {
                    problem.value =
                        status.state === 'stale'
                            ? trans(
                                  'The machine stopped answering. Check it’s online and try again.',
                              )
                            : (status.error?.message ??
                              trans('The scan didn’t finish. Try again.'));
                }
                return;
            }
        } catch {
            // Try again on the next tick.
        }
        timer = setTimeout(tick, 1500);
    };
    void tick();
}

async function copy(command: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(command);
        copied.value = command;
        setTimeout(() => {
            if (copied.value === command) copied.value = null;
        }, 1500);
    } catch {
        // Clipboard blocked: the command is on screen to select.
    }
}
</script>

<template>
    <div v-if="canManage" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-(--ds-text-muted)">
                {{
                    scan
                        ? $t(':used used, :available free.', {
                              used: bytes(scan.used),
                              available: bytes(scan.available),
                          })
                        : $t(
                              'See which folders take the most space, and what’s safe to clear. Nothing is deleted.',
                          )
                }}
            </p>
            <AppButton
                size="sm"
                :disabled="scanning || !online"
                :title="online ? undefined : $t('The machine is offline.')"
                @click="start"
            >
                <LoaderCircle
                    v-if="scanning"
                    class="size-3.5 animate-spin opacity-60"
                />
                <HardDrive v-else class="size-3.5 opacity-60" />
                {{
                    scanning
                        ? $t('Scanning the disk')
                        : scan
                          ? $t('Scan again')
                          : $t('See what’s using it')
                }}
            </AppButton>
        </div>

        <p v-if="problem" class="text-sm text-(--ds-red)" role="alert">
            {{ problem }}
        </p>

        <template v-if="scan">
            <ul v-if="scan.suggestions.length" class="flex flex-col gap-2">
                <li
                    v-for="suggestion in scan.suggestions"
                    :key="suggestion.title"
                    class="ds-tile px-4 py-3 text-sm"
                >
                    <p class="font-medium">{{ suggestion.title }}</p>
                    <p class="mt-0.5 text-pretty text-(--ds-text-muted)">
                        {{ suggestion.detail }}
                    </p>
                    <div
                        v-if="suggestion.command"
                        class="mt-2 flex items-center gap-2"
                    >
                        <code
                            class="min-w-0 truncate rounded-md bg-(--ds-active) px-2 py-1 font-mono text-[12px]/4"
                            >{{ suggestion.command }}</code
                        >
                        <button
                            type="button"
                            class="cursor-pointer rounded-md p-1 text-(--ds-text-muted) hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :aria-label="$t('Copy command')"
                            @click="copy(suggestion.command)"
                        >
                            <Check
                                v-if="copied === suggestion.command"
                                class="size-3.5"
                            />
                            <Copy v-else class="size-3.5" />
                        </button>
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-(--ds-text-muted)">
                {{
                    $t(
                        'Nothing obvious to clear. If the biggest folders below are data you need, resize the disk with your provider.',
                    )
                }}
            </p>

            <div
                v-if="scan.dirs.length"
                class="ds-tile divide-y-2 divide-black/5 text-sm dark:divide-black/30"
            >
                <div
                    v-for="dir in scan.dirs"
                    :key="dir.path"
                    class="flex justify-between gap-3 px-4 py-2"
                >
                    <span class="min-w-0 truncate font-mono text-[12.5px]">{{
                        dir.path
                    }}</span>
                    <span
                        class="flex shrink-0 items-center gap-2.5 tabular-nums"
                    >
                        <span
                            class="h-1.5 w-20 overflow-hidden rounded-full bg-(--ds-active)"
                            aria-hidden="true"
                        >
                            <span
                                class="block h-full rounded-full bg-(--ds-blue)"
                                :style="{
                                    width: `${biggest ? (dir.bytes / biggest) * 100 : 0}%`,
                                }"
                            />
                        </span>
                        {{ bytes(dir.bytes) }}
                    </span>
                </div>
            </div>
            <p v-if="scan.partial" class="text-[13px] text-(--ds-text-muted)">
                {{
                    $t(
                        'The disk is big, so the scan stopped after 45 seconds. Some folders may be missing.',
                    )
                }}
            </p>
        </template>
    </div>
</template>
