<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, CircleDashed, LoaderCircle, Search } from '@lucide/vue';
import {
    SolarCircleCheck as CircleCheck,
    SolarCircleX as CircleX,
    SolarLock as Lock,
    SolarRotateCw as RotateCw,
    SolarServer as Server,
} from '@/lib/solarIcons';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ProviderName from '@/components/machines/ProviderName.vue';
import UpdatesDialog from '@/components/machines/UpdatesDialog.vue';
import RolloutDetailDialog from '@/components/updates/RolloutDetailDialog.vue';
import RolloutDialog from '@/components/updates/RolloutDialog.vue';
import {
    rolloutStatus,
    sendJson,
    toneText,
} from '@/components/updates/updates';
import type {
    MachineGroupChoice,
    Rollout,
    UpdatesMachine,
    UpdatesPackage,
    UpdatesSummary,
} from '@/components/updates/updates';
import AppButton from '@/components/workspace/AppButton.vue';
import IconTip from '@/components/workspace/IconTip.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { providerBrand } from '@/lib/providerBrand';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import type { Workspace, WorkspaceAccess } from '@/types';

type View = 'packages' | 'machines' | 'rollouts';

const props = defineProps<{
    workspace: Workspace;
    summary: UpdatesSummary;
    machines: UpdatesMachine[];
    packages: UpdatesPackage[];
    checking: number[];
    rollouts: Rollout[];
    groups: MachineGroupChoice[];
    commandsEnabled: boolean;
    view: View | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        (page.props.workspaceAccess?.permissions.includes('manage-machines') ??
            false) &&
        props.commandsEnabled,
);

const view = ref<View>(props.view ?? 'packages');
const views = computed<{ value: View; label: string; count: number }[]>(() => [
    {
        value: 'packages',
        label: trans('By package'),
        count: props.packages.filter((p) => p.machineIds.length).length,
    },
    {
        value: 'machines',
        label: trans('By machine'),
        count: props.machines.length,
    },
    {
        value: 'rollouts',
        label: trans('Rollouts'),
        count: props.rollouts.length,
    },
]);

const byId = computed(
    () => new Map(props.machines.map((machine) => [machine.id, machine])),
);

const summaryLine = computed(() => {
    const s = props.summary;
    if (s.total === 0) return '';
    const parts: string[] = [];
    if (s.updates === 0) {
        parts.push(
            s.checkedAt
                ? trans('Everything checked is up to date.')
                : trans('No machine has been checked yet.'),
        );
    } else {
        parts.push(
            (s.machines === 1
                ? trans(
                      s.updates === 1
                          ? ':updates update on 1 machine'
                          : ':updates updates on 1 machine',
                      { updates: String(s.updates) },
                  )
                : trans(':updates updates on :machines machines', {
                      updates: String(s.updates),
                      machines: String(s.machines),
                  })) +
                (s.security
                    ? trans(', :security security.', {
                          security: String(s.security),
                      })
                    : '.'),
        );
    }
    if (s.checkedAt) {
        parts.push(
            trans('Last checked :when.', { when: timeAgo(s.newestCheck) }),
        );
    }
    if (s.rebootRequired) {
        parts.push(
            s.rebootRequired === 1
                ? trans('1 machine needs a restart.')
                : trans(':count machines need a restart.', {
                      count: String(s.rebootRequired),
                  }),
        );
    }
    if (s.restartServices) {
        parts.push(
            s.restartServices === 1
                ? trans('1 machine has services to restart.')
                : trans(':count machines have services to restart.', {
                      count: String(s.restartServices),
                  }),
        );
    }
    return parts.join(' ');
});

const whenVisible = useWhenVisible();
let pollTimer: ReturnType<typeof setTimeout> | undefined;
const busy = computed(
    () =>
        props.checking.length > 0 ||
        props.rollouts.some(
            (r) =>
                r.state === 'running' ||
                (r.state === 'pending' && !r.scheduledFor),
        ),
);

function reload(): void {
    router.reload({
        only: ['summary', 'machines', 'packages', 'checking', 'rollouts'],
    });
}

watch(
    busy,
    (value) => {
        clearTimeout(pollTimer);
        if (!value) return;
        const tick = () => {
            whenVisible(reload);
            pollTimer = setTimeout(tick, 4000);
        };
        pollTimer = setTimeout(tick, 4000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(pollTimer));

const checkingAll = ref(false);
async function checkAll(): Promise<void> {
    if (checkingAll.value) return;
    checkingAll.value = true;
    try {
        const { queued } = await sendJson<{ queued: number }>(
            'POST',
            '/updates/check',
        );
        toast.success(
            queued === 1
                ? trans('Checking 1 machine. This can take a minute.')
                : trans('Checking :count machines. This can take a minute.', {
                      count: String(queued),
                  }),
        );
        reload();
    } catch (cause) {
        toast.error((cause as Error).message);
    } finally {
        checkingAll.value = false;
    }
}

const search = ref('');
const selected = ref<string[]>([]);
const visiblePackages = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.packages.filter((p) => !q || p.name.toLowerCase().includes(q));
});
const selectedPackages = computed(() =>
    props.packages.filter((p) => selected.value.includes(p.name)),
);
const allSelected = computed(
    () =>
        visiblePackages.value.length > 0 &&
        visiblePackages.value.every((p) => selected.value.includes(p.name)),
);

watch(
    () => props.packages,
    (list) => {
        selected.value = selected.value.filter((name) =>
            list.some((p) => p.name === name),
        );
    },
);

function select(name: string, on: boolean): void {
    selected.value = on
        ? [...new Set([...selected.value, name])]
        : selected.value.filter((n) => n !== name);
}

function selectAll(on: boolean): void {
    selected.value = on ? visiblePackages.value.map((p) => p.name) : [];
}

function versionsLine(pkg: UpdatesPackage): string {
    if (pkg.versions.length === 0) return trans('Nothing waiting');
    if (pkg.versions.length === 1) {
        const v = pkg.versions[0];
        return v.machineIds.length === 1
            ? trans(':version on 1 machine', { version: v.version })
            : trans(':version on :count machines', {
                  version: v.version,
                  count: String(v.machineIds.length),
              });
    }
    return trans(':count versions', { count: String(pkg.versions.length) });
}

function versionsTitle(pkg: UpdatesPackage): string {
    return pkg.versions
        .map(
            (v) =>
                `${v.version} (${v.os ?? v.release}): ${v.machineIds
                    .map((id) => byId.value.get(id)?.name ?? id)
                    .join(', ')}`,
        )
        .join('\n');
}

const expanded = ref<string[]>([]);
function toggleExpanded(name: string): void {
    expanded.value = expanded.value.includes(name)
        ? expanded.value.filter((n) => n !== name)
        : [...expanded.value, name];
}

function packageMachines(pkg: UpdatesPackage): {
    id: number;
    name: string;
    provider: string | null;
    online: boolean;
    version: string | null;
    held: boolean;
}[] {
    return pkg.machineIds.map((id) => ({
        id,
        name: byId.value.get(id)?.name ?? `#${id}`,
        provider: byId.value.get(id)?.provider ?? null,
        online: byId.value.get(id)?.online ?? true,
        version:
            pkg.versions.find((v) => v.machineIds.includes(id))?.version ??
            null,
        held: pkg.heldOn.includes(id),
    }));
}

const rolloutOpenDialog = ref(false);
const rollingOut = computed(() =>
    selectedPackages.value.filter((p) => p.machineIds.length > 0),
);

const holding = ref(false);
async function hold(on: boolean): Promise<void> {
    const packages = selectedPackages.value;
    const machineIds = [
        ...new Set(packages.flatMap((p) => (on ? p.machineIds : p.heldOn))),
    ];
    if (!machineIds.length || holding.value) return;
    holding.value = true;
    try {
        const result = await sendJson<{
            queued: { machineId: number; machine: string; jobId: string }[];
            skipped: { machine: string; reason: string }[];
        }>('POST', '/updates/holds', {
            packages: packages.map((p) => p.name),
            machines: machineIds,
            hold: on,
        });
        toast.success(
            on
                ? trans('Holding on :count machines.', {
                      count: String(result.queued.length),
                  })
                : trans('Letting go on :count machines.', {
                      count: String(result.queued.length),
                  }),
        );
        for (const skip of result.skipped) {
            toast.warning(`${skip.machine}: ${skip.reason}`);
        }
        void followHolds(result.queued);
    } catch (cause) {
        toast.error((cause as Error).message);
    } finally {
        holding.value = false;
    }
}

async function followHolds(
    jobs: { machineId: number; machine: string; jobId: string }[],
): Promise<void> {
    let waiting = [...jobs];
    for (let round = 0; round < 20 && waiting.length; round++) {
        await new Promise((resolve) => setTimeout(resolve, 2000));
        const next: typeof waiting = [];
        for (const job of waiting) {
            try {
                const status = await sendJson<{
                    state: string;
                    error: { message?: string } | null;
                }>('GET', `/machines/${job.machineId}/updates/${job.jobId}`);
                if (['queued', 'running'].includes(status.state)) {
                    next.push(job);
                } else if (status.state !== 'succeeded') {
                    toast.error(
                        `${job.machine}: ${status.error?.message ?? trans('That didn’t finish.')}`,
                    );
                }
            } catch {
                next.push(job);
            }
        }
        waiting = next;
    }
    reload();
}

const machineDialog = ref<UpdatesMachine | null>(null);
const machineDialogOpen = ref(false);
function openMachine(machine: UpdatesMachine): void {
    machineDialog.value = machine;
    machineDialogOpen.value = true;
}
watch(machineDialogOpen, (value) => {
    if (!value) reload();
});

const rolloutId = ref<number | null>(null);
const rolloutDetailOpen = ref(false);
function openRollout(id: number): void {
    rolloutId.value = id;
    rolloutDetailOpen.value = true;
}
function rolloutCreated(rollout: Rollout): void {
    selected.value = [];
    view.value = 'rollouts';
    reload();
    openRollout(rollout.id);
}

const rolloutIcons: Record<string, Component> = {
    pending: CircleDashed,
    running: LoaderCircle,
    done: CircleCheck,
    failed: CircleX,
    cancelled: CircleDashed,
    paused: CircleDashed,
};

function rolloutDetail(rollout: Rollout): string {
    if (rollout.state === 'pending' && rollout.scheduledFor) {
        return trans('Starts :when', {
            when: new Date(rollout.scheduledFor).toLocaleString(),
        });
    }
    return trans(':finished of :total done', {
        finished: String(rollout.finished),
        total: String(rollout.total),
    });
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const rowButton =
    'group flex min-h-11 w-full items-center gap-3 px-4 py-2 text-left text-[13.5px]/5 transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Updates')" />

    <WorkspacePage :title="$t('Updates')" toolbar>
        <template #description>
            {{
                $t(
                    'Operating system updates waiting on your machines. Each one is checked about once a day.',
                )
            }}
        </template>

        <template v-if="machines.length" #tabs>
            <div class="max-w-full overflow-x-auto">
                <div class="ds-segments" role="group" :aria-label="$t('View')">
                    <button
                        v-for="option in views"
                        :key="option.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="view === option.value"
                        @click="view = option.value"
                    >
                        {{ option.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                view === option.value
                                    ? 'text-(--ds-text-muted)'
                                    : 'text-(--ds-text-faint)'
                            "
                        >
                            {{ option.count }}
                        </span>
                    </button>
                </div>
            </div>
        </template>

        <template v-if="machines.length" #actions>
            <label
                v-if="view === 'packages'"
                class="ds-raised-button w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-56"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="sr-only">{{ $t('Search packages') }}</span>
                <input
                    v-model="search"
                    type="search"
                    :placeholder="$t('Search packages')"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                />
            </label>
            <AppButton
                v-if="canManage"
                size="sm"
                :disabled="checkingAll"
                @click="checkAll"
            >
                <RotateCw
                    class="size-3.5 opacity-60"
                    :class="{
                        'animate-spin motion-reduce:animate-none':
                            checking.length > 0,
                    }"
                    :stroke-width="1.75"
                />
                {{ $t('Check all now') }}
            </AppButton>
        </template>

        <div
            v-if="!machines.length"
            class="ds-tile flex flex-col items-start gap-1 px-5 py-5"
        >
            <p class="text-[14px]/5 font-medium">
                {{ $t('No machines to update yet') }}
            </p>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'Connect a machine and its operating system updates show up here, checked about once a day.',
                    )
                }}
            </p>
            <AppButton class="mt-3" size="sm" href="/machines">
                {{ $t('Go to Machines') }}
            </AppButton>
        </div>

        <template v-else>
            <p
                class="-mt-1 text-[13px]/5 text-(--ds-text-muted) tabular-nums"
                role="status"
            >
                {{ summaryLine }}
                <span v-if="checking.length" class="text-(--ds-blue)">
                    {{
                        ' ' +
                        (checking.length === 1
                            ? $t('Checking 1 machine now.')
                            : $t('Checking :count machines now.', {
                                  count: String(checking.length),
                              }))
                    }}
                </span>
            </p>

            <section
                v-if="view === 'packages'"
                class="flex flex-col gap-3"
                aria-labelledby="packages-heading"
            >
                <div class="flex min-h-8 flex-wrap items-center gap-3">
                    <h2
                        id="packages-heading"
                        class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                    >
                        {{ $t('Packages') }}
                    </h2>
                    <div
                        v-if="canManage && selected.length"
                        class="ml-auto flex flex-wrap items-center gap-2"
                    >
                        <span class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                $t(':count selected', {
                                    count: String(selected.length),
                                })
                            }}
                        </span>
                        <AppButton
                            v-if="selectedPackages.some((p) => p.heldOn.length)"
                            size="sm"
                            :disabled="holding"
                            @click="hold(false)"
                        >
                            {{ $t('Let go') }}
                        </AppButton>
                        <AppButton
                            v-if="rollingOut.length"
                            size="sm"
                            :disabled="holding"
                            @click="hold(true)"
                        >
                            <Lock
                                class="size-3.5 opacity-60"
                                :stroke-width="1.75"
                            />
                            {{ $t('Hold') }}
                        </AppButton>
                        <AppButton
                            v-if="rollingOut.length"
                            size="sm"
                            variant="primary"
                            @click="rolloutOpenDialog = true"
                        >
                            {{ $t('Roll out') }}
                        </AppButton>
                    </div>
                </div>

                <div
                    v-if="!packages.length"
                    class="ds-tile px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                >
                    {{
                        summary.checkedAt
                            ? $t(
                                  'Nothing to update. Every machine checked is up to date.',
                              )
                            : $t(
                                  'No machine has been checked yet. Checks run on their own about once a day, or check all now.',
                              )
                    }}
                </div>
                <ul v-else class="ds-tile ds-tile-rows flex flex-col">
                    <li
                        v-if="canManage"
                        class="ds-tile-row flex h-10 items-center gap-3 px-4 text-[13px]/5 text-(--ds-text-muted)"
                    >
                        <label
                            class="flex cursor-pointer items-center gap-3 select-none"
                        >
                            <TickBox
                                :model-value="allSelected"
                                @update:model-value="selectAll"
                            />
                            {{ $t('Select all') }}
                        </label>
                    </li>
                    <li
                        v-for="pkg in visiblePackages"
                        :key="pkg.name"
                        class="ds-tile-row"
                    >
                        <label
                            class="flex min-h-11 items-center gap-3 px-4 py-2 text-[13.5px]/5"
                            :class="{
                                'cursor-pointer hover:bg-(--ds-row-hover)':
                                    canManage,
                            }"
                        >
                            <TickBox
                                v-if="canManage"
                                :model-value="selected.includes(pkg.name)"
                                :aria-label="
                                    $t('Select :name', { name: pkg.name })
                                "
                                @update:model-value="
                                    (on: boolean) => select(pkg.name, on)
                                "
                            />
                            <span class="flex min-w-0 items-center gap-2">
                                <span
                                    class="truncate font-ui-mono text-[12.5px]/5 font-medium"
                                    >{{ pkg.name }}</span
                                >
                                <span
                                    v-if="pkg.security"
                                    class="shrink-0 rounded-[4px] bg-(--ds-amber)/12 px-1.5 text-[11px]/4.5 font-medium text-(--ds-amber)"
                                    >{{ $t('Security') }}</span
                                >
                            </span>
                            <span
                                class="hidden min-w-0 truncate font-ui-mono text-[12px] text-(--ds-text-faint) md:inline"
                                :title="versionsTitle(pkg)"
                                >{{ versionsLine(pkg) }}</span
                            >
                            <span
                                class="ml-auto flex shrink-0 items-center gap-4 text-[13px] text-(--ds-text-muted) tabular-nums"
                            >
                                <span
                                    v-if="pkg.heldOn.length"
                                    class="flex items-center gap-1"
                                    :title="
                                        pkg.heldOn
                                            .map((id) => byId.get(id)?.name)
                                            .join(', ')
                                    "
                                >
                                    <Lock
                                        class="size-3"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    {{
                                        $t('held on :count', {
                                            count: String(pkg.heldOn.length),
                                        })
                                    }}
                                </span>
                                <button
                                    v-if="pkg.machineIds.length"
                                    type="button"
                                    class="-mr-1.5 flex w-28 cursor-pointer items-center justify-end gap-1 rounded-md px-1.5 py-0.5 hover:bg-(--ds-row-hover) hover:text-(--ds-text)"
                                    :aria-expanded="expanded.includes(pkg.name)"
                                    @click.prevent.stop="
                                        toggleExpanded(pkg.name)
                                    "
                                >
                                    {{
                                        pkg.machineIds.length === 1
                                            ? $t('1 machine')
                                            : $t(':count machines', {
                                                  count: String(
                                                      pkg.machineIds.length,
                                                  ),
                                              })
                                    }}
                                    <ChevronRight
                                        class="size-3.5 shrink-0 transition-transform"
                                        :class="{
                                            'rotate-90': expanded.includes(
                                                pkg.name,
                                            ),
                                        }"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                </button>
                            </span>
                        </label>
                        <ul
                            v-if="expanded.includes(pkg.name)"
                            class="relative pb-2"
                            :style="{ '--trunk': canManage ? '50px' : '22px' }"
                        >
                            <li
                                v-for="(m, i) in packageMachines(pkg)"
                                :key="m.id"
                                class="relative"
                            >
                                <span
                                    aria-hidden="true"
                                    class="pointer-events-none absolute top-0 left-(--trunk) w-4 border-l-[1.5px] border-(--ds-text-faint)/45"
                                    :class="
                                        i === pkg.machineIds.length - 1
                                            ? 'h-1/2 rounded-bl-[7px] border-b-[1.5px]'
                                            : 'h-full'
                                    "
                                />
                                <span
                                    v-if="i !== pkg.machineIds.length - 1"
                                    aria-hidden="true"
                                    class="pointer-events-none absolute top-1/2 left-(--trunk) w-4 border-t-[1.5px] border-(--ds-text-faint)/45"
                                />
                                <Link
                                    :href="`/machines/${m.id}`"
                                    class="group/branch flex h-8 items-center gap-2 pr-4 pl-[calc(var(--trunk)+24px)] text-[13px]"
                                >
                                    <span
                                        aria-hidden="true"
                                        class="absolute top-1/2 left-[calc(var(--trunk)+17px)] size-2 -translate-x-1/2 -translate-y-1/2 rounded-full border-[1.5px] border-(--ds-text-faint)/70 bg-(--ds-surface) transition-colors group-hover/branch:border-(--ds-blue) group-hover/branch:bg-(--ds-blue)"
                                    />
                                    <span
                                        v-if="providerBrand(m.provider)"
                                        class="ml-1.5 flex size-3.5 shrink-0 items-center"
                                    >
                                        <ProviderName
                                            :name="m.provider"
                                            logo-only
                                        />
                                    </span>
                                    <Server
                                        v-else
                                        class="ml-1.5 size-3.5 shrink-0 text-(--ds-text-faint)"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="min-w-0 truncate font-medium group-hover/branch:underline group-hover/branch:decoration-(--ds-border-strong) group-hover/branch:underline-offset-2"
                                        >{{ m.name }}</span
                                    >
                                    <span
                                        v-if="m.version"
                                        class="min-w-0 truncate font-ui-mono text-[12px] text-(--ds-text-faint)"
                                        >{{ m.version }}</span
                                    >
                                    <span
                                        class="ml-auto flex shrink-0 items-center gap-3 text-[12.5px] text-(--ds-text-faint)"
                                    >
                                        <span
                                            v-if="m.held"
                                            class="flex items-center gap-1"
                                        >
                                            <Lock
                                                class="size-3"
                                                :stroke-width="1.75"
                                                aria-hidden="true"
                                            />
                                            {{ $t('Held') }}
                                        </span>
                                        <span v-if="!m.online">{{
                                            $t('Offline')
                                        }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </li>
                    <li
                        v-if="!visiblePackages.length"
                        class="ds-tile-row px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                    >
                        {{ $t('No packages match your search.') }}
                    </li>
                </ul>
                <p
                    v-if="machines.some((m) => !m.complete && m.updates)"
                    class="text-[12.5px]/4.5 text-(--ds-text-muted)"
                >
                    {{
                        $t(
                            'Some machines run an older daemon that only lists the first few dozen updates. Update it with sudo serverosd update to see them all.',
                        )
                    }}
                </p>
            </section>

            <section
                v-else-if="view === 'machines'"
                class="flex flex-col gap-3"
                aria-labelledby="machines-heading"
            >
                <h2
                    id="machines-heading"
                    class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                >
                    {{ $t('Machines') }}
                </h2>
                <ul class="ds-tile ds-tile-rows flex flex-col">
                    <li
                        v-for="machine in machines"
                        :key="machine.id"
                        class="ds-tile-row"
                    >
                        <component
                            :is="canManage ? 'button' : Link"
                            :type="canManage ? 'button' : undefined"
                            :href="
                                canManage
                                    ? undefined
                                    : `/machines/${machine.id}`
                            "
                            :class="[
                                rowButton,
                                'cursor-pointer hover:bg-(--ds-row-hover)',
                            ]"
                            @click="canManage && openMachine(machine)"
                        >
                            <IconTip
                                v-if="providerBrand(machine.provider)"
                                :label="machine.provider ?? ''"
                            >
                                <span class="flex size-4 shrink-0 items-center">
                                    <ProviderName
                                        :name="machine.provider"
                                        logo-only
                                    />
                                </span>
                            </IconTip>
                            <Server
                                v-else
                                class="size-4 shrink-0 text-(--ds-text-faint)"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 truncate font-medium">{{
                                machine.name
                            }}</span>
                            <span
                                class="hidden min-w-0 truncate text-[13px] text-(--ds-text-faint) md:inline"
                                >{{ machine.os }}</span
                            >
                            <span
                                class="ml-auto flex shrink-0 items-center gap-4 text-[13px] text-(--ds-text-muted) tabular-nums"
                            >
                                <span
                                    v-if="machine.rebootRequired"
                                    class="hidden text-(--ds-amber) sm:inline"
                                    >{{ $t('Restart needed') }}</span
                                >
                                <span
                                    v-else-if="machine.staleServices"
                                    class="hidden text-(--ds-amber) sm:inline"
                                    >{{
                                        machine.staleServices === 1
                                            ? $t('1 service to restart')
                                            : $t(':count services to restart', {
                                                  count: String(
                                                      machine.staleServices,
                                                  ),
                                              })
                                    }}</span
                                >
                                <span
                                    v-if="machine.held.length"
                                    class="hidden items-center gap-1 lg:flex"
                                    :title="machine.held.join(', ')"
                                >
                                    <Lock
                                        class="size-3"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    {{ machine.held.length }}
                                </span>
                                <span
                                    v-if="checking.includes(machine.id)"
                                    class="text-(--ds-blue)"
                                    >{{ $t('Checking…') }}</span
                                >
                                <span v-else-if="!machine.checkedAt">{{
                                    $t('Not checked yet')
                                }}</span>
                                <span v-else-if="machine.updates === 0">{{
                                    $t('Up to date')
                                }}</span>
                                <span v-else>
                                    {{
                                        machine.updates === 1
                                            ? $t('1 update')
                                            : $t(':count updates', {
                                                  count: String(
                                                      machine.updates,
                                                  ),
                                              })
                                    }}<span
                                        v-if="machine.security"
                                        class="text-(--ds-amber)"
                                    >
                                        ·
                                        {{
                                            $t(':count security', {
                                                count: String(machine.security),
                                            })
                                        }}</span
                                    >
                                </span>
                                <span
                                    class="hidden w-28 text-right text-(--ds-text-faint) lg:inline"
                                    :title="
                                        machine.checkedAt
                                            ? new Date(
                                                  machine.checkedAt,
                                              ).toLocaleString()
                                            : undefined
                                    "
                                    >{{
                                        machine.checkedAt
                                            ? timeAgo(machine.checkedAt)
                                            : ''
                                    }}</span
                                >
                                <span
                                    v-if="!machine.online"
                                    class="text-(--ds-text-faint)"
                                    >{{ $t('Offline') }}</span
                                >
                                <ChevronRight
                                    class="size-3.5 text-(--ds-text-faint) transition-transform group-hover:translate-x-0.5"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                            </span>
                        </component>
                    </li>
                </ul>
            </section>

            <section
                v-else
                class="flex flex-col gap-3"
                aria-labelledby="rollouts-heading"
            >
                <div class="flex flex-col gap-0.5">
                    <h2
                        id="rollouts-heading"
                        class="text-[15px]/6 font-semibold tracking-[-0.01em]"
                    >
                        {{ $t('Rollouts') }}
                    </h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'Updates installed on several machines at once, or a batch at a time.',
                            )
                        }}
                    </p>
                </div>
                <ul
                    v-if="rollouts.length"
                    class="ds-tile ds-tile-rows flex flex-col"
                >
                    <li
                        v-for="rollout in rollouts"
                        :key="rollout.id"
                        class="ds-tile-row"
                    >
                        <button
                            type="button"
                            :class="[
                                rowButton,
                                'cursor-pointer hover:bg-(--ds-row-hover)',
                            ]"
                            @click="openRollout(rollout.id)"
                        >
                            <component
                                :is="rolloutIcons[rollout.state]"
                                class="size-4 shrink-0"
                                :class="[
                                    toneText[rolloutStatus(rollout).tone],
                                    {
                                        'animate-spin motion-reduce:animate-none':
                                            rollout.state === 'running',
                                    },
                                ]"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 truncate font-medium">{{
                                rollout.name
                            }}</span>
                            <span
                                class="hidden shrink-0 text-[13px] text-(--ds-text-faint) md:inline"
                                >{{
                                    rollout.mode === 'exact'
                                        ? $t('Exact versions')
                                        : $t('Latest')
                                }}</span
                            >
                            <span
                                class="ml-auto flex shrink-0 items-center gap-4 text-[13px] text-(--ds-text-muted) tabular-nums"
                            >
                                <span
                                    :class="
                                        toneText[rolloutStatus(rollout).tone]
                                    "
                                    >{{ rolloutStatus(rollout).label }}</span
                                >
                                <span class="hidden sm:inline">{{
                                    rolloutDetail(rollout)
                                }}</span>
                                <span
                                    v-if="rollout.failed"
                                    class="text-(--ds-red)"
                                    >{{
                                        $t(':count failed', {
                                            count: String(rollout.failed),
                                        })
                                    }}</span
                                >
                                <span
                                    class="hidden w-28 text-right lg:inline"
                                    >{{ timeAgo(rollout.createdAt) }}</span
                                >
                                <ChevronRight
                                    class="size-3.5 text-(--ds-text-faint) transition-transform group-hover:translate-x-0.5"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                            </span>
                        </button>
                    </li>
                </ul>
                <p
                    v-else
                    class="ds-tile px-4 py-3.5 text-[13.5px]/5 text-(--ds-text-muted)"
                >
                    {{
                        canManage
                            ? $t(
                                  'No rollouts yet. Pick packages under By package, then Roll out.',
                              )
                            : $t('No rollouts yet.')
                    }}
                </p>
            </section>
        </template>

        <RolloutDialog
            v-model:open="rolloutOpenDialog"
            :packages="rollingOut"
            :machines="machines"
            :groups="groups"
            @created="rolloutCreated"
        />
        <RolloutDetailDialog
            v-model:open="rolloutDetailOpen"
            :rollout-id="rolloutId"
            :can-manage="canManage"
            @changed="reload"
        />
        <UpdatesDialog
            v-if="machineDialog"
            v-model:open="machineDialogOpen"
            :machine-id="machineDialog.id"
            :machine-name="machineDialog.name"
        />
    </WorkspacePage>
</template>
