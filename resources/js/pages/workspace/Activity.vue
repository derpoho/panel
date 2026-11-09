<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, ChevronDown } from '@lucide/vue';
import {
    SolarDownload as Download,
    SolarHistory as History,
} from '@/lib/solarIcons';
import { computed, ref, watch } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { getInitials } from '@/composables/useInitials';
import type { Workspace } from '@/types';

type Entry = {
    id: number;
    at: string;
    /** support: ServerOS staff did it for the workspace (never says who). */
    user: { name: string; avatar: string | null; support: boolean } | null;
    machine: { id: number; name: string } | null;
    action: string;
    verb: string;
    subject: string | null;
    after: string | null;
    detail: string | null;
    mono: boolean;
    tone: 'neutral' | 'good' | 'warn' | 'bad';
    /** System events read "<subject> <verb>". */
    leads: boolean;
    /**
     * Where it came from, for owners and admins only. On a ServerOS
     * Support entry there's no device or IP: those were staff's.
     */
    origin: {
        source: string | null;
        client: string | null;
        device: string | null;
        ip: string | null;
        userAgent: string | null;
        requestId: string | null;
    } | null;
};

type Person = {
    /** A user id, or "gone-…" for an account that was deleted. */
    value: string;
    name: string;
    avatar: string | null;
    left: boolean;
};

type When = 'today' | '7d' | '30d';

type Filters = {
    who: 'people' | 'system' | 'support' | null;
    person: string | null;
    group: string | null;
    machine: number | null;
    when: When | null;
    from: string | null;
    to: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    machines: { id: number; name: string }[];
    people: Person[];
    groups: { value: string; label: string }[];
    filters: Filters;
    entries: Entry[];
    hasMore: boolean;
    keepDays: number;
    canExport: boolean;
}>();

/* Older pages are appended; a filter change starts over. */
const entries = ref<Entry[]>([...props.entries]);
const more = ref(props.hasMore);
const loading = ref(false);

watch(
    () => props.entries,
    (fresh) => {
        if (loading.value) {
            entries.value = [...entries.value, ...fresh];
            loading.value = false;
        } else {
            entries.value = [...fresh];
        }
        more.value = props.hasMore;
    },
);

function query(
    changes: Partial<Filters> & { before?: number },
): Record<string, string | number> {
    const next = { ...props.filters, ...changes };
    const out: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(next)) {
        if (value !== null && value !== undefined) out[key] = value;
    }
    // Days are the reader's days.
    if (next.when || next.from || next.to) {
        out.tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    return out;
}

const filtered = computed(() =>
    Object.values(props.filters).some((value) => value !== null),
);

const exportHref = computed(() => {
    const params = new URLSearchParams(
        Object.entries(query({})).map(([k, v]) => [k, String(v)]),
    ).toString();

    return params ? `/activity/export?${params}` : '/activity/export';
});

function clearFilters(): void {
    custom.value = false;
    apply({
        who: null,
        person: null,
        group: null,
        machine: null,
        when: null,
        from: null,
        to: null,
    });
}

function apply(changes: Partial<Filters>): void {
    router.get('/activity', query(changes), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['entries', 'hasMore', 'filters'],
    });
}

function loadMore(): void {
    const last = entries.value.at(-1);
    if (!last || loading.value) return;
    loading.value = true;
    router.get('/activity', query({ before: last.id }), {
        // Older rows join the list; the address stays on the newest.
        preserveUrl: true,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['entries', 'hasMore'],
        onError: () => (loading.value = false),
    });
}

const members = computed(() => props.people.filter((p) => !p.left));
const former = computed(() => props.people.filter((p) => p.left));

const whoLabel = computed(() => {
    const person = props.people.find((p) => p.value === props.filters.person);
    if (person) return person.name;

    return props.filters.who === 'people'
        ? trans('People')
        : props.filters.who === 'system'
          ? trans('Automatic')
          : props.filters.who === 'support'
            ? trans('ServerOS Support')
            : trans('Everyone');
});

function chooseWho(who: Filters['who'], person: string | null = null): void {
    apply({ who, person });
}

const groupLabel = computed(
    () =>
        props.groups.find((g) => g.value === props.filters.group)?.label ??
        trans('Everything'),
);

const whenOptions = computed(
    () =>
        [
            { value: null, label: trans('Any time') },
            { value: 'today', label: trans('Today') },
            { value: '7d', label: trans('Last 7 days') },
            { value: '30d', label: trans('Last 30 days') },
        ] as const,
);

/* The two date fields show once "Custom range" is picked, or a range is set. */
const custom = ref(props.filters.from !== null || props.filters.to !== null);

const whenLabel = computed(() =>
    custom.value
        ? trans('Custom range')
        : (whenOptions.value.find((o) => o.value === props.filters.when)
              ?.label ?? trans('Any time')),
);

function chooseWhen(when: When | null): void {
    custom.value = false;
    apply({ when, from: null, to: null });
}

function chooseCustom(): void {
    custom.value = true;
    if (props.filters.when) apply({ when: null });
}

/* Where an entry came from, opened one at a time by its time. */
const opened = ref<number | null>(null);

function originLine(origin: NonNullable<Entry['origin']>): string {
    const source =
        origin.source === 'mcp'
            ? origin.client
                ? trans('MCP (:client)', { client: origin.client })
                : 'MCP'
            : origin.source === 'api'
              ? origin.client
                  ? trans('API (:client)', { client: origin.client })
                  : 'API'
              : origin.source === 'web'
                ? trans('Web')
                : origin.source === 'workflow'
                  ? trans('Workflow')
                  : origin.source === 'machine'
                    ? trans('Machine')
                    : origin.source === 'system'
                      ? trans('Automatic')
                      : null;

    return [
        origin.device ?? origin.userAgent,
        origin.ip,
        source,
        origin.requestId
            ? trans('Ref :id', { id: origin.requestId.slice(0, 8) })
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
}
const machineLabel = computed(
    () =>
        props.machines.find((m) => m.id === props.filters.machine)?.name ??
        trans('All'),
);

/** Entries with a heading wherever the day changes. */
const rows = computed(() => {
    const out: (
        | { type: 'day'; key: string; label: string }
        | { type: 'entry'; key: number; entry: Entry }
    )[] = [];
    let previous = '';
    const today = new Date().toDateString();
    const yesterday = new Date(Date.now() - 86_400_000).toDateString();
    const thisYear = new Date().getFullYear();

    for (const entry of entries.value) {
        const date = new Date(entry.at);
        const key = date.toDateString();
        if (key !== previous) {
            out.push({
                type: 'day',
                key: `day-${entry.id}`,
                label:
                    key === today
                        ? trans('Today')
                        : key === yesterday
                          ? trans('Yesterday')
                          : date.toLocaleDateString(undefined, {
                                weekday: 'long',
                                day: 'numeric',
                                month: 'long',
                                // Business keeps a year: say which one.
                                year:
                                    date.getFullYear() === thisYear
                                        ? undefined
                                        : 'numeric',
                            }),
            });
            previous = key;
        }
        out.push({ type: 'entry', key: entry.id, entry });
    }

    return out;
});

/*
 * One formatter each, made once rather than per row (toLocaleString()
 * builds a new one every call). Same output: the second is what
 * toLocaleString() with no options shows.
 */
const timeFormat = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
});
const fullFormat = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
});

function time(iso: string): string {
    return timeFormat.format(new Date(iso));
}

function fullTime(iso: string): string {
    return fullFormat.format(new Date(iso));
}

const toneDot: Record<Entry['tone'], string> = {
    neutral: 'bg-(--ds-text-faint)',
    good: 'bg-(--ds-green)',
    warn: 'bg-(--ds-amber)',
    bad: 'bg-(--ds-red)',
};

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const dateField =
    'ds-raised-button h-8 cursor-text bg-transparent px-2 text-[13px]/4 text-(--ds-text) outline-none';
</script>

<template>
    <Head :title="$t('Activity')" />

    <WorkspacePage :title="$t('Activity')">
        <template #description>
            {{
                ' ' +
                $t(
                    'Who did what, and what happened on its own, over the last :keepDays days. The first place to look when something changed and nobody knows why.',
                    { keepDays: String(keepDays ?? '') },
                ) +
                ' '
            }}
        </template>

        <template #actions>
            <button
                v-if="filtered"
                type="button"
                class="cursor-pointer text-[13px]/5 font-medium text-(--ds-text-secondary) hover:text-(--ds-text)"
                :class="focusRing"
                @click="clearFilters"
            >
                {{ $t('Clear filters') }}
            </button>
            <a
                v-if="canExport"
                :href="exportHref"
                class="ds-raised-button cursor-pointer"
                :title="$t('Download what’s shown as a CSV')"
                download
            >
                <Download class="size-3.5 opacity-60" :stroke-width="1.75" />
                {{ $t('Export') + ' ' }}
            </a>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('Who')
                        }}</span>
                        <span class="font-medium">{{ whoLabel }}</span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal max-h-96 min-w-52 overflow-y-auto font-ui"
                    align="end"
                >
                    <DropdownMenuItem
                        v-for="option in [
                            { value: null, label: $t('Everyone') },
                            { value: 'system', label: $t('Automatic') },
                            { value: 'support', label: $t('ServerOS Support') },
                        ] as const"
                        :key="option.label"
                        @select="chooseWho(option.value)"
                    >
                        {{ option.label }}
                        <Check
                            v-if="
                                filters.who === option.value &&
                                filters.person === null
                            "
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <template v-if="members.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-medium text-(--ds-text-muted)"
                            >{{ $t('Members') }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="person in members"
                            :key="person.value"
                            @select="chooseWho(null, person.value)"
                        >
                            <Avatar class="size-5 shrink-0 rounded-full">
                                <AvatarImage
                                    v-if="person.avatar"
                                    :src="person.avatar"
                                    :alt="person.name"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-(--ds-hover) text-[9px] text-(--ds-text-secondary)"
                                >
                                    {{ getInitials(person.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span class="truncate">{{ person.name }}</span>
                            <Check
                                v-if="filters.person === person.value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </template>
                    <template v-if="former.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-medium text-(--ds-text-muted)"
                            >{{ $t('Former members') }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="person in former"
                            :key="person.value"
                            @select="chooseWho(null, person.value)"
                        >
                            <Avatar class="size-5 shrink-0 rounded-full">
                                <AvatarImage
                                    v-if="person.avatar"
                                    :src="person.avatar"
                                    :alt="person.name"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-(--ds-hover) text-[9px] text-(--ds-text-secondary)"
                                >
                                    {{ getInitials(person.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span class="truncate">{{
                                $t(':name (left)', { name: person.name })
                            }}</span>
                            <Check
                                v-if="filters.person === person.value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('What')
                        }}</span>
                        <span class="max-w-40 truncate font-medium">{{
                            groupLabel
                        }}</span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-52 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="apply({ group: null })">
                        {{ ' ' + $t('Everything') }}
                        <Check
                            v-if="filters.group === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="group in groups"
                        :key="group.value"
                        @select="apply({ group: group.value })"
                    >
                        {{ group.label }}
                        <Check
                            v-if="filters.group === group.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('When')
                        }}</span>
                        <span class="font-medium">{{ whenLabel }}</span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="end"
                >
                    <DropdownMenuItem
                        v-for="option in whenOptions"
                        :key="option.label"
                        @select="chooseWhen(option.value)"
                    >
                        {{ option.label }}
                        <Check
                            v-if="!custom && filters.when === option.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem @select="chooseCustom">
                        {{ ' ' + $t('Custom range…') }}
                        <Check v-if="custom" class="ml-auto size-3.5" />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <template v-if="custom">
                <label class="sr-only" for="activity-from">{{
                    $t('From')
                }}</label>
                <input
                    id="activity-from"
                    type="date"
                    :class="[dateField, focusRing]"
                    :title="$t('From')"
                    :value="filters.from ?? ''"
                    :max="filters.to ?? undefined"
                    @change="
                        apply({
                            from:
                                ($event.target as HTMLInputElement).value ||
                                null,
                        })
                    "
                />
                <label class="sr-only" for="activity-to">{{ $t('To') }}</label>
                <input
                    id="activity-to"
                    type="date"
                    :class="[dateField, focusRing]"
                    :title="$t('To')"
                    :value="filters.to ?? ''"
                    :min="filters.from ?? undefined"
                    @change="
                        apply({
                            to:
                                ($event.target as HTMLInputElement).value ||
                                null,
                        })
                    "
                />
            </template>

            <DropdownMenu v-if="machines.length > 1">
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="ds-raised-button cursor-pointer text-[13px]!"
                        :class="focusRing"
                    >
                        <span class="text-(--ds-text-muted)">{{
                            $t('Machine')
                        }}</span>
                        <span class="max-w-32 truncate font-medium">
                            {{ machineLabel }}
                        </span>
                        <ChevronDown
                            class="size-2.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal max-h-80 min-w-40 overflow-y-auto font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="apply({ machine: null })">
                        {{ ' ' + $t('All') }}
                        <Check
                            v-if="filters.machine === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="machine in machines"
                        :key="machine.id"
                        @select="apply({ machine: machine.id })"
                    >
                        {{ machine.name }}
                        <Check
                            v-if="filters.machine === machine.id"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>

        <div
            v-if="entries.length === 0"
            class="ds-tile flex flex-col items-center px-6 py-20 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <History class="size-4.5" :stroke-width="2" />
            </div>
            <h2 class="mt-5 text-base/6 font-semibold">
                {{ filtered ? $t('Nothing matches') : $t('Nothing yet') }}
            </h2>
            <p
                class="mt-1.5 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
            >
                {{
                    ' ' +
                    $t(
                        'Deploys, restarts, console commands, settings changes, machines going quiet and monitors going down all show up here.',
                    ) +
                    ' '
                }}
            </p>
        </div>

        <div v-else class="ds-tile overflow-hidden">
            <ol>
                <template v-for="row in rows" :key="row.key">
                    <li
                        v-if="row.type === 'day'"
                        class="sticky top-0 z-10 border-b border-(--ds-divider) bg-(--ds-card) px-5 py-2 text-xs/4 font-medium text-(--ds-text-muted)"
                    >
                        {{ row.label }}
                    </li>
                    <li
                        v-else
                        class="ds-tile-row grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 px-5 py-3 text-[13px]/5"
                    >
                        <span
                            v-if="row.entry.user?.support"
                            class="mt-px flex size-5 shrink-0 items-center justify-center rounded-full bg-(--ds-hover)"
                            :title="
                                $t(
                                    'Done by ServerOS Support for your workspace',
                                )
                            "
                        >
                            <img
                                src="/images/landing/serveros-logo.svg"
                                alt=""
                                class="size-3 object-contain invert dark:invert-0"
                            />
                        </span>
                        <Avatar
                            v-else-if="row.entry.user"
                            class="mt-px size-5 shrink-0 rounded-full"
                        >
                            <AvatarImage
                                v-if="row.entry.user.avatar"
                                :src="row.entry.user.avatar"
                                :alt="row.entry.user.name"
                            />
                            <AvatarFallback
                                class="rounded-full bg-(--ds-hover) text-[9px] text-(--ds-text-secondary)"
                            >
                                {{ getInitials(row.entry.user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            v-else
                            class="mt-px flex size-5 items-center justify-center"
                            :title="$t('Happened on its own')"
                        >
                            <span
                                class="size-2 rounded-full"
                                :class="toneDot[row.entry.tone]"
                            />
                        </span>

                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-pretty">
                                <template v-if="row.entry.leads">
                                    <span class="font-medium">{{
                                        row.entry.subject
                                    }}</span>
                                    {{ row.entry.verb }}
                                </template>
                                <template v-else>
                                    <span class="font-medium">{{
                                        row.entry.user?.name ?? 'ServerOS'
                                    }}</span>
                                    {{ row.entry.verb }}
                                    <span
                                        v-if="row.entry.subject"
                                        class="font-medium"
                                        >{{ row.entry.subject }}</span
                                    >
                                </template>
                                <span
                                    v-if="row.entry.after"
                                    class="text-(--ds-text-secondary)"
                                    >{{ ` ${row.entry.after}` }}</span
                                >
                                <template
                                    v-if="
                                        row.entry.machine &&
                                        row.entry.subject !==
                                            row.entry.machine.name
                                    "
                                >
                                    <span class="text-(--ds-text-muted)">
                                        {{ ' ' + $t('on') + ' ' }}
                                    </span>
                                    <Link
                                        :href="`/machines/${row.entry.machine.id}`"
                                        class="text-(--ds-text-secondary) hover:text-(--ds-text) hover:underline hover:underline-offset-4"
                                        >{{ row.entry.machine.name }}</Link
                                    >
                                </template>
                            </span>
                            <span
                                v-if="row.entry.detail"
                                class="text-(--ds-text-secondary)"
                                :class="
                                    row.entry.mono
                                        ? 'w-fit max-w-full truncate rounded-[5px] bg-(--ds-code) px-1.5 py-0.5 font-ui-mono text-xs'
                                        : 'line-clamp-2'
                                "
                                :title="row.entry.detail"
                            >
                                {{ row.entry.detail }}
                            </span>
                            <span
                                v-if="
                                    opened === row.entry.id && row.entry.origin
                                "
                                :id="`origin-${row.entry.id}`"
                                class="text-xs/4.5 text-(--ds-text-muted)"
                                :title="row.entry.origin.userAgent ?? undefined"
                            >
                                {{
                                    originLine(row.entry.origin) ||
                                    $t(
                                        'Nothing recorded about where this came from.',
                                    )
                                }}
                            </span>
                        </span>

                        <button
                            v-if="row.entry.origin"
                            type="button"
                            class="cursor-pointer rounded text-xs/5 text-(--ds-text-muted) tabular-nums hover:text-(--ds-text) hover:underline hover:decoration-dotted hover:underline-offset-4"
                            :class="focusRing"
                            :aria-expanded="opened === row.entry.id"
                            :aria-controls="`origin-${row.entry.id}`"
                            :title="$t('Where this came from')"
                            @click="
                                opened =
                                    opened === row.entry.id
                                        ? null
                                        : row.entry.id
                            "
                        >
                            <time
                                :datetime="row.entry.at"
                                :title="fullTime(row.entry.at)"
                                >{{ time(row.entry.at) }}</time
                            >
                        </button>
                        <time
                            v-else
                            class="text-xs/5 text-(--ds-text-muted) tabular-nums"
                            :datetime="row.entry.at"
                            :title="fullTime(row.entry.at)"
                        >
                            {{ time(row.entry.at) }}
                        </time>
                    </li>
                </template>
            </ol>
            <button
                v-if="more"
                type="button"
                class="w-full cursor-pointer border-t border-(--ds-divider) px-5 py-2.5 text-[13px]/5 font-medium text-(--ds-text-secondary) hover:bg-(--ds-row-hover) hover:text-(--ds-text) disabled:opacity-60"
                :class="focusRing"
                :disabled="loading"
                @click="loadMore"
            >
                {{ loading ? $t('Loading…') : $t('Show older') }}
            </button>
        </div>
    </WorkspacePage>
</template>
